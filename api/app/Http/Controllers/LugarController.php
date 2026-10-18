<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreLugarRequest;
use App\Models\Estacionamiento;
use App\Models\Lugar;
use App\Models\Parqueadero;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Lugares: el mapa fisico de la sede.
 *
 * El mapa es lo primero que se configura y lo ultimo que se entiende: lo que
 * mas se consulta en el dia a dia.
 *
 * DESACTIVAR ES MAS RIGUROSO QUE CREAR
 * -------------------------------------
 * Un lugar con un vehiculo adentro no se puede desactivar. No es una regla de
 * estilo: al desactivarlo, la sesion abierta sigue apuntando a un lugar que
 * el operador ya no ofrece, y el conteo de ocupacion empieza a discrepar de
 * lo que se ve en el piso. El reclamo llega a las dos horas.
 *
 * Los lugares inactivos no se borran, se apagan. El registro de movimientos
 * guarda el codigo del lugar tal como estaba ese dia, y un codigo borrado
 * deja movimientos sin lugar al que apuntar.
 */
class LugarController extends Controller
{
    public function index(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Lugar::class);

        $lugares = Lugar::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->orderBy('codigo')
            ->get()
            ->map(fn (Lugar $lugar): array => $this->presentar($lugar));

        return response()->json(['lugares' => $lugares]);
    }

    public function store(StoreLugarRequest $request, Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('create', Lugar::class);

        $lugar = Lugar::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'parqueadero_id' => $parqueadero->id,
            ...$request->safe()->only(['codigo', 'tipo', 'reservado_para']),
        ]);

        return response()->json($this->presentar($lugar), 201);
    }

    /**
     * El codigo no se edita: es lo que esta en el suelo y en la memoria de
     * los operarios. Para corregirlo hay que desactivar el lugar y crear
     * otro. Ver `StoreLugarRequest`.
     */
    public function update(StoreLugarRequest $request, Lugar $lugar): JsonResponse
    {
        Gate::authorize('update', $lugar);

        $lugar->fill($request->safe()->only(['tipo', 'reservado_para']))->save();

        return response()->json($this->presentar($lugar->fresh()));
    }

    public function desactivar(Lugar $lugar): JsonResponse
    {
        Gate::authorize('desactivar', $lugar);

        $conVehiculo = Estacionamiento::query()
            ->where('lugar_id', $lugar->id)
            ->whereNull('salida_en')
            ->exists();

        if ($conVehiculo) {
            return response()->json([
                'mensaje' => sprintf(
                    'El lugar %s tiene un vehiculo adentro. Cobre la salida antes de desactivarlo.',
                    $lugar->codigo
                ),
                'codigo' => 'lugar_con_vehiculo',
            ], 422);
        }

        $lugar->activo = false;
        $lugar->save();

        return response()->json($this->presentar($lugar->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Lugar $lugar): array
    {
        return [
            'id' => $lugar->id,
            'parqueadero_id' => $lugar->parqueadero_id,
            'codigo' => $lugar->codigo,
            'tipo' => $lugar->tipo,
            'tipo_etiqueta' => $lugar->etiquetaDeTipo(),
            'reservado_para' => $lugar->reservado_para,
            'activo' => $lugar->activo,
        ];
    }
}