<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Models\AnotacionMovimiento;
use App\Models\Estacionamiento;
use App\Models\Movimiento;
use App\Models\Parqueadero;
use App\Models\Usuario;
use App\Services\Estacionamiento\RelojDelServidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Movimientos: el registro, y solo lectura.
 *
 * NO HAY NI UN METODO QUE MODIFIQUE UN MOVIMIENTO
 * -----------------------------------------------
 * Ni `update`, ni `delete`, ni un `anular` que escriba sobre la fila. La
 * anotacion (`anotar`) AGREGA texto al lado; la anulacion, si se llegara a
 * implementar, escribiria un movimiento nuevo.
 *
 * La garantia no es solo la ausencia de codigo: la migracion 000017 pone un
 * gatillo en PostgreSQL que rechaza `UPDATE` y `DELETE` sobre la tabla. Un
 * `psql` con la contrasena de la base tampoco puede reescribir el pasado de
 * un estacionamiento.
 *
 * LAS DOS CONSULTAS QUE MAS SE USAN
 * ---------------------------------
 *  - `historial`: por sede y fecha, para el turno del dia.
 *  - `deLaSesion`: todo lo que le pasó a un vehiculo en una visita, que es
 *    lo que se mira cuando hay un reclamo.
 */
class MovimientoController extends Controller
{
    public function historial(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Movimiento::class);

        $validacion = Validator::make(request()->query(), [
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'patente' => ['nullable', 'string', 'max:12'],
            'tipo' => ['nullable', 'string', 'in:' . implode(',', array_column(TipoMovimiento::cases(), 'value'))],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $validacion->validate();

        $desde = request()->query('desde', Carbon::now()->startOfDay()->toDateTimeString());
        $hasta = request()->query('hasta', Carbon::now()->endOfDay()->toDateTimeString());

        $consulta = Movimiento::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->whereBetween('ocurrido_en', [$desde, $hasta]);

        if (is_string(request()->query('patente'))) {
            $consulta->where('patente', mb_strtoupper((string) request()->query('patente')));
        }

        if (is_string(request()->query('tipo'))) {
            $consulta->where('tipo', (string) request()->query('tipo'));
        }

        $porPagina = (int) request()->query('por_pagina', 100);

        $movimientos = $consulta
            ->with('anotaciones')
            ->orderByDesc('ocurrido_en')
            ->limit($porPagina)
            ->get()
            ->map(fn (Movimiento $movimiento): array => $this->presentar($movimiento));

        return response()->json([
            'movimientos' => $movimientos,
            'ventana' => ['desde' => $desde, 'hasta' => $hasta],
        ]);
    }

    public function deLaSesion(Estacionamiento $estacionamiento): JsonResponse
    {
        Gate::authorize('viewAny', Movimiento::class);

        $movimientos = Movimiento::query()
            ->where('estacionamiento_id', $estacionamiento->id)
            ->with('anotaciones')
            ->orderBy('ocurrido_en')
            ->get()
            ->map(fn (Movimiento $movimiento): array => $this->presentar($movimiento));

        return response()->json([
            'sesion' => [
                'id' => $estacionamiento->id,
                'patente' => $estacionamiento->patente,
                'vin' => $estacionamiento->vin,
                'ingreso_en' => $estacionamiento->ingreso_en->toIso8601String(),
                'salida_en' => $estacionamiento->salida_en?->toIso8601String(),
                'segundos_permanencia' => $estacionamiento->segundos_permanencia,
                'boleta_id' => $estacionamiento->boleta_id,
            ],
            'movimientos' => $movimientos,
        ]);
    }

    /**
     * Agrega una aclaracion al registro.
     *
     * Esta es la UNICA operacion de escritura sobre movimientos, y no toca la
     * fila del movimiento: escribe en `anotaciones_movimiento`, que es la
     * tabla que la migracion 000017 deja abierta a proposito.
     */
    public function anotar(Request $request, Movimiento $movimiento): JsonResponse
    {
        Gate::authorize('anotar', $movimiento);

        $datos = $request->validate([
            'texto' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], [
            'texto' => 'anotación',
        ]);

        $usuario = $request->user();

        $anotacion = AnotacionMovimiento::query()->create([
            'tenant_id' => $movimiento->tenant_id,
            'movimiento_id' => $movimiento->id,
            'usuario_id' => $usuario->id,
            'texto' => $datos['texto'],
            'creado_en' => RelojDelServidor::ahora()->toDateTime(),
        ]);

        return response()->json([
            'anotacion' => [
                'id' => $anotacion->id,
                'texto' => $anotacion->texto,
                'creado_en' => $anotacion->creado_en->toIso8601String(),
                'usuario' => $usuario instanceof Usuario ? $usuario->nombre : null,
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Movimiento $movimiento): array
    {
        return [
            'id' => $movimiento->id,
            'tipo' => $movimiento->tipo->value,
            'tipo_etiqueta' => $movimiento->tipo->etiqueta(),
            'ocurrido_en' => $movimiento->ocurrido_en->toIso8601String(),
            'patente' => $movimiento->patente,
            'vin' => $movimiento->vin,
            'tipo_vehiculo' => $movimiento->tipo_vehiculo->value,
            'lugar_codigo' => $movimiento->lugar_codigo,
            'tiene_foto' => $movimiento->tieneFoto(),
            'foto_ruta' => $movimiento->foto_ruta,
            'estacionamiento_id' => $movimiento->estacionamiento_id,
            'anotaciones' => $movimiento->anotaciones->map(fn (AnotacionMovimiento $anotacion): array => [
                'id' => $anotacion->id,
                'texto' => $anotacion->texto,
                'creado_en' => $anotacion->creado_en->toIso8601String(),
            ])->all(),
        ];
    }
}