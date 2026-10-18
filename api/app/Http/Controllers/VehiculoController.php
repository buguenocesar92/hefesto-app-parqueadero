<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Vehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Vehiculos: el padron de autos que el operador reconoce.
 *
 * UN VEHICULO SE DA DE ALTA SOLO CON EL INGRESO
 * ---------------------------------------------
 * No hay una pantalla para "crear vehiculo". `RegistradorIngreso` crea la
 * fila la primera vez que el auto entra, y solo con lo que el operador tiene
 * delante: patente, VIN, tipo y, si los escribe, marca, modelo y color.
 *
 * El motivo es que el padron escrito a mano se desactualiza solo: en dos
 * meses la mitad de los autos ya no estan en el mismo auto.
 *
 * LO QUE ESTE CONTROLADOR HACE ES LEER
 * ------------------------------------
 * Buscar un vehiculo por VIN o por patente, para el mostrador y para
 * revisar una mensualidad. Es lectura pura.
 */
class VehiculoController extends Controller
{
    public function index(): JsonResponse
    {
        $busqueda = request()->query('q');

        $consulta = Vehiculo::query()->orderBy('patente');

        if (is_string($busqueda) && trim($busqueda) !== '') {
            $termino = mb_strtoupper(trim($busqueda));

            $consulta->where(function ($interna) use ($termino): void {
                $interna->where('patente', $termino)
                    ->orWhere('vin', mb_strtoupper($termino))
                    ->orWhere('marca', 'ilike', '%' . $termino . '%')
                    ->orWhere('modelo', 'ilike', '%' . $termino . '%');
            });
        }

        $vehiculos = $consulta->limit(200)->get()
            ->map(fn (Vehiculo $vehiculo): array => $this->presentar($vehiculo));

        return response()->json(['vehiculos' => $vehiculos]);
    }

    public function show(Vehiculo $vehiculo): JsonResponse
    {
        return response()->json($this->presentar($vehiculo->load('mensualidad')));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Vehiculo $vehiculo): array
    {
        return [
            'id' => $vehiculo->id,
            'patente' => $vehiculo->patente,
            'vin' => $vehiculo->vin,
            'tipo_vehiculo' => $vehiculo->tipo_vehiculo->value,
            'tipo_vehiculo_etiqueta' => $vehiculo->tipo_vehiculo->etiqueta(),
            'descripcion' => $vehiculo->descripcion(),
            'color' => $vehiculo->color,
            'marca' => $vehiculo->marca,
            'modelo' => $vehiculo->modelo,
            'tiene_mensualidad' => $vehiculo->mensualidad !== null,
        ];
    }
}