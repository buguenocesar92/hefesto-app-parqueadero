<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Parqueadero;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Sedes: los estacionamientos fisicos de un operador.
 *
 * Un operador puede tener varias sedes. Todo lo del dia a dia cuelga de una
 * sede y no del operador, y por eso casi todas las rutas llevan el id de la
 * sede: las tarifas de Ñuñoa no son las de Maipú.
 *
 * La RLS ya filtra por operador. Este controlador no repite ese filtro:
 * anadirlo seria dar la sensacion de que el filtro de la base no basta, que
 * es justo lo contrario de lo que se quiere que se entienda.
 */
class ParqueaderoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Parqueadero::class);

        $sedes = Parqueadero::query()
            ->orderBy('nombre')
            ->get()
            ->map(fn (Parqueadero $sede): array => $this->presentar($sede));

        return response()->json(['sedes' => $sedes]);
    }

    public function show(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('view', $parqueadero);

        return response()->json($this->presentar($parqueadero));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Parqueadero $sede): array
    {
        return [
            'id' => $sede->id,
            'nombre' => $sede->nombre,
            'direccion' => $sede->direccion,
            'capacidad_total' => $sede->capacidad_total,
            'zona_horaria' => $sede->zona_horaria,
            'activo' => $sede->activo,
        ];
    }
}