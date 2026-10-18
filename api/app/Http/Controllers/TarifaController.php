<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeParqueadero;
use App\Http\Requests\StoreTarifaRequest;
use App\Models\Parqueadero;
use App\Models\Tarifa;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Carbon;

/**
 * Tarifas: el precio.
 *
 * UNA TARIFA NO SE EDITA UNA VEZ COBRADA
 * --------------------------------------
 * Este controlador es deliberadamente pequeno. No hay `update` que cambie el
 * `valor` de una tarifa en vigencia: para subir el precio se crea una tarifa
 * nueva y se cierra la anterior con `vigente_hasta`.
 *
 * El motivo esta en `GeneradorDeBoleta`: la boleta guarda el `desglose` del
 * momento en que se emitio, pero la tarifa que lo produjo se busca por la
 * fecha del ingreso. Si una tarifa de marzo cambia de valor en abril, la
 * boleta de marzo tiene que seguir explicando lo mismo.
 *
 * LA EXCEPCION DOCUMENTADA
 * -----------------------
 * El unico caso editable es una tarifa cuya vigencia empieza en el futuro:
 * todavia no cobro nadie, asi que corregir un error de tipeo no reescribe
 * nada. Lo autoriza `TarifaPolicy::update()`.
 *
 * POR QUE NO HAY `destroy`
 * -----------------------
 * Una tarifa borrada deja a las boletas del periodo sin la tarifa que las
 * produjo. Se desactiva, y con eso deja de aplicar a clientes nuevos.
 */
class TarifaController extends Controller
{
    public function index(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Tarifa::class);

        $tarifas = Tarifa::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->orderByDesc('vigente_desde')
            ->get()
            ->map(fn (Tarifa $tarifa): array => $this->presentar($tarifa));

        return response()->json(['tarifas' => $tarifas]);
    }

    /** Solo las que rigen ahora mismo: es lo que necesita la pantalla. */
    public function vigentes(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Tarifa::class);

        $ahora = Carbon::now();

        $tarifas = Tarifa::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->where('activo', true)
            ->where('vigente_desde', '<=', $ahora)
            ->where(function ($consulta) use ($ahora): void {
                $consulta->whereNull('vigente_hasta')
                    ->orWhere('vigente_hasta', '>=', $ahora);
            })
            ->orderByDesc('vigente_desde')
            ->get()
            ->map(fn (Tarifa $tarifa): array => $this->presentar($tarifa));

        return response()->json(['tarifas' => $tarifas]);
    }

    public function store(StoreTarifaRequest $request, Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('crear', Tarifa::class);

        $datos = $request->validated();

        $tarifa = Tarifa::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'parqueadero_id' => $parqueadero->id,
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'],
            'valor' => (int) $datos['valor'],
            'minutos_por_fraccion' => $datos['minutos_por_fraccion'] ?? null,
            'minutos_incluidos' => (int) ($datos['minutos_incluidos'] ?? 0),
            'tipo_vehiculo' => $datos['tipo_vehiculo'] ?? null,
            // Vigencia por omision: ahora, no la medianoche de hoy. Un
            // aumento pedido a las 11 raje a las 11.
            'vigente_desde' => $datos['vigente_desde'] ?? Carbon::now(),
            'vigente_hasta' => $datos['vigente_hasta'] ?? null,
            'activo' => $datos['activo'] ?? true,
        ]);

        if (! $tarifa->estaCompleta()) {
            // No deberia llegar aca: el FormRequest ya lo rechaza. Se deja
            // la comprobacion igual, porque un fallo aqui seria que el cobro
            // reviente en la barrera.
            throw new ErrorDeParqueadero(
                'La tarifa quedo incompleta y no se podria calcular un cobro con ella.',
                'tarifa_incompleta',
            );
        }

        return response()->json($this->presentar($tarifa), 201);
    }

    public function update(StoreTarifaRequest $request, Tarifa $tarifa): JsonResponse
    {
        Gate::authorize('update', $tarifa);

        $datos = $request->validated();

        $tarifa->fill([
            'nombre' => $datos['nombre'],
            'valor' => (int) $datos['valor'],
            'minutos_por_fraccion' => $datos['minutos_por_fraccion'] ?? null,
            'minutos_incluidos' => (int) ($datos['minutos_incluidos'] ?? 0),
            'tipo_vehiculo' => $datos['tipo_vehiculo'] ?? null,
            'vigente_desde' => $datos['vigente_desde'] ?? $tarifa->vigente_desde,
            'vigente_hasta' => $datos['vigente_hasta'] ?? null,
            'activo' => $datos['activo'] ?? $tarifa->activo,
        ])->save();

        return response()->json($this->presentar($tarifa->fresh()));
    }

    public function desactivar(Tarifa $tarifa): JsonResponse
    {
        Gate::authorize('desactivar', $tarifa);

        $tarifa->activo = false;

        // Cerrar la vigencia evita que la tarifa siga siendo candidata para
        // los ingresos de la sesion siguiente: sin esto, un ingreso de hoy
        // podria tomar una tarifa que ya se dio de baja.
        if ($tarifa->vigente_hasta === null) {
            $tarifa->vigente_hasta = Carbon::now();
        }

        $tarifa->save();

        return response()->json($this->presentar($tarifa->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Tarifa $tarifa): array
    {
        return [
            'id' => $tarifa->id,
            'parqueadero_id' => $tarifa->parqueadero_id,
            'nombre' => $tarifa->nombre,
            'tipo' => $tarifa->tipo,
            'tipo_etiqueta' => $tarifa->etiqueta(),
            'valor' => $tarifa->valor,
            'minutos_por_fraccion' => $tarifa->minutos_por_fraccion,
            'minutos_incluidos' => $tarifa->minutos_incluidos,
            'tipo_vehiculo' => $tarifa->tipo_vehiculo,
            'vigente_desde' => $tarifa->vigente_desde->toIso8601String(),
            'vigente_hasta' => $tarifa->vigente_hasta?->toIso8601String(),
            'activo' => $tarifa->activo,
            'completa' => $tarifa->estaCompleta(),
            'editable' => $tarifa->vigente_desde->isFuture(),
        ];
    }
}