<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoReclamo;
use App\Models\Boleta;
use App\Models\Estacionamiento;
use App\Models\Mensualidad;
use App\Models\Parqueadero;
use App\Models\Reclamo;
use App\Services\Estacionamiento\CalculadoraOcupacion;
use App\Services\Estacionamiento\CalculadoraTarifa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Panel: lo que el operador mira al abrir el sistema.
 *
 * QUE VA AQUI Y QUE NO
 * --------------------
 * Va lo que se necesita para decidir que hacer en los proximos diez
 * minutos: cuantos lugares hay, cuantos reclamos hay abiertos, cuanto se
 * cobro hoy.
 *
 * NO va un historial largo ni un detalle de boletas. El panel se mira de pie,
 * con un cliente esperando en la caseta. Si el panel fuera completo, dejaria
 * de usarse, que es lo que pasa con los tableros que muestran veinte
 * numeros.
 *
 * UNA CONSULTA POR SEDE, NO UNA POR LA BASE ENTERA
 * -----------------------------------------------
 * Cada bloque cuenta por `parqueadero_id`. Contar sobre `estacionamientos` sin
 * filtro de sede obliga a revisar years de historial para responder "ahora
 * mismo", y el indice parcial `indice_ocupacion_por_sede` existe justo para
 * que ese conteo sea un recorrido de indice y no de tabla.
 */
class PanelController extends Controller
{
    /** Resumen de una sede. */
    public function show(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Estacionamiento::class);

        $hoy = Carbon::now();

        $ventana = [
            $hoy->copy()->startOfDay()->toDateTimeString(),
            $hoy->copy()->endOfDay()->toDateTimeString(),
        ];

        return response()->json([
            'parqueadero' => [
                'id' => $parqueadero->id,
                'nombre' => $parqueadero->nombre,
            ],
            'ocupacion' => app(CalculadoraOcupacion::class)->estado($parqueadero->id),
            'hoy' => [
                ...app(CalculadoraTarifa::class)->totalDelDia($parqueadero->id, ...$ventana),
                'entradas' => Estacionamiento::query()
                    ->where('parqueadero_id', $parqueadero->id)
                    ->whereBetween('ingreso_en', $ventana)
                    ->count(),
                'salidas' => Estacionamiento::query()
                    ->where('parqueadero_id', $parqueadero->id)
                    ->whereNotNull('salida_en')
                    ->whereBetween('salida_en', $ventana)
                    ->count(),
                'boletas_anuladas' => Boleta::query()
                    ->where('parqueadero_id', $parqueadero->id)
                    ->where('estado', Boleta::ESTADO_ANULADA)
                    ->whereBetween('emitida_en', $ventana)
                    ->count(),
            ],
            'reclamos' => [
                'abiertos' => $this->reclamosAbiertos($parqueadero->id),
                'en_revision' => $this->reclamosEn(EstadoReclamo::EnRevision, $parqueadero->id),
            ],
            'mensualidades' => [
                'activas' => Mensualidad::query()
                    ->where('parqueadero_id', $parqueadero->id)
                    ->where('estado', Mensualidad::ESTADO_ACTIVA)
                    ->count(),
                'por_vencer' => Mensualidad::query()
                    ->where('parqueadero_id', $parqueadero->id)
                    ->where('estado', Mensualidad::ESTADO_ACTIVA)
                    ->whereBetween('credencial_vence_en', [
                        $hoy->toDateString(),
                        $hoy->copy()->addDays((int) config('parqueadero.dias_aviso_vencimiento', 15))->toDateString(),
                    ])
                    ->count(),
            ],
            'momento' => now()->toIso8601String(),
        ]);
    }

    /**
     * El panel de TODAS las sedes del operador.
     *
     * Es lo que se ve al entrar, antes de elegir sede: si el operador tiene
     * tres, tiene que ver como estan las tres de un vistazo.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Parqueadero::class);

        $ocupacion = app(CalculadoraOcupacion::class);

        $sedes = Parqueadero::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get()
            ->map(fn (Parqueadero $sede): array => [
                'id' => $sede->id,
                'nombre' => $sede->nombre,
                'ocupacion' => $ocupacion->estado($sede->id),
                'reclamos_abiertos' => $this->reclamosAbiertos($sede->id),
            ]);

        return response()->json([
            'sedes' => $sedes,
            'total_sedes' => $sedes->count(),
        ]);
    }

    private function reclamosAbiertos(string $parqueaderoId): int
    {
        return $this->reclamosEn(EstadoReclamo::Abierto, $parqueaderoId);
    }

    private function reclamosEn(EstadoReclamo $estado, string $parqueaderoId): int
    {
        return Reclamo::query()
            ->where('parqueadero_id', $parqueaderoId)
            ->where('estado', $estado->value)
            ->count();
    }
}