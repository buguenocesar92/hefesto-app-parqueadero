<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lugar;
use App\Models\Parqueadero;
use App\Services\Estacionamiento\CalculadoraOcupacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Ocupacion: la pantalla que mira el operador antes de dejar pasar un
 * vehiculo.
 *
 * DE DONDE SALE EL NUMERO
 * -----------------------
 * De `CalculadoraOcupacion`, no de un `count` escrito aqui. Ese servicio
 * tiene la logica de por que el conteo se hace contra las SESIONES ABIERTAS y
 * no contra la tabla de lugares, y por que se apoya en el indice unico
 * parcial. La razon esta escrita ahi y no se repite.
 *
 * CAPACIDAD DECLARADA Y CAPACIDAD REAL
 * -----------------------------------
 * `parqueaderos.capacidad_total` es lo que el operador DECLARA. La cantidad
 * de lugares es la que existe. Cuando no coinciden, este endpoint lo dice en
 * vez de escolher una de las dos: un sistema que esconde esa diferencia
 * termina prometiendo un puesto que no existe.
 */
class OcupacionController extends Controller
{
    /** Fotografia de una sede. */
    public function estado(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Lugar::class);

        $estado = app(CalculadoraOcupacion::class)->estado($parqueadero->id);

        return response()->json([
            'parqueadero_id' => $parqueadero->id,
            'nombre' => $parqueadero->nombre,
            ...$estado,
            'capacidad_declarada' => $parqueadero->capacidad_total,
            'capacidad_real' => $estado['capacidad'],
            'coincide' => $parqueadero->capacidad_total === $estado['capacidad'],
        ]);
    }

    /**
     * Los lugares con quien los tiene.
     *
     * El estado de cada lugar sale de su sesion abierta, que es la unica
     * fuente. No hay un campo `ocupado` que pueda quedar desactualizado: si
     * el lugar esta en la lista de ocupados, hay una fila en
     * `estacionamientos` con `salida_en` nulo, y esa fila no puede ser de
     * otro lugar porque el indice unico lo impide.
     */
    public function lugares(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Lugar::class);

        $lugares = Lugar::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->with(['sesionAbierta' => fn ($consulta) => $consulta->with('vehiculo')])
            ->orderBy('codigo')
            ->get()
            ->map(fn (Lugar $lugar): array => [
                'id' => $lugar->id,
                'codigo' => $lugar->codigo,
                'tipo' => $lugar->tipo,
                'tipo_etiqueta' => $lugar->etiquetaDeTipo(),
                'reservado_para' => $lugar->reservado_para,
                'activo' => $lugar->activo,
                'ocupado' => $lugar->sesionAbierta !== null,
                'sesion_id' => $lugar->sesionAbierta?->id,
                'patente' => $lugar->sesionAbierta?->patente,
                'vin' => $lugar->sesionAbierta?->vin,
                'tipo_vehiculo' => $lugar->sesionAbierta?->vehiculo?->tipo_vehiculo?->value,
                'ingreso_en' => $lugar->sesionAbierta?->ingreso_en->toIso8601String(),
            ]);

        return response()->json(['lugares' => $lugares]);
    }
}