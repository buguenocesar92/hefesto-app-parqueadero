<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeParqueadero;
use App\Http\Requests\StoreSalidaRequest;
use App\Models\Boleta;
use App\Models\Estacionamiento;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Services\Boletas\ReconstructorDeBoleta;
use App\Services\Estacionamiento\CalculadoraOcupacion;
use App\Services\Estacionamiento\CalculadoraTiempo;
use App\Services\Estacionamiento\RegistradorSalida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * Salida de vehiculos: el momento en que se cobra.
 *
 * TRES CAMINOS DE ENTRADA, UNO SOLO DE COBRO
 * -------------------------------------------
 *
 *  - con el id de la sesion: el camino normal, el del ticket en la mano;
 *  - con patente: el cliente perdio el papel y el operador lo busca en
 *    pantalla;
 *  - con VIN: cuando hay dos vehiculos de la misma familia con la misma
 *    patente, que es el caso en que la patente no sirve.
 *
 * Los tres terminan en el mismo metodo y, sobre todo, en la misma regla: el
 * tiempo que se cobra sale de `ingreso_en` y `salida_en`, que estan en la
 * base. Nunca de un dato que mande el cliente.
 */
class SalidaController extends Controller
{
    public function store(StoreSalidaRequest $request, RegistradorSalida $salidas): JsonResponse
    {
        $sesion = $this->localizar($request, $salidas);

        try {
            $boleta = $salidas->registrar($sesion, $request->user(), [
                'forma_pago' => (string) $request->validated('forma_pago'),
                'descuento' => (int) $request->validated('descuento', 0),
                'foto_ruta' => $request->validated('foto_ruta'),
                'salida_en' => $request->validated('salida_en'),
            ]);
        } catch (RuntimeException $error) {
            // Los servicios que ya estaban en el repositorio lanzan la clase
            // base `RuntimeException`, no una excepcion propia.
            return response()->json([
                'mensaje' => $error->getMessage(),
                'codigo' => 'salida_rechazada',
            ], 422);
        }

        return response()->json([
            'boleta' => $this->presentar($boleta),
            'ocupacion' => app(CalculadoraOcupacion::class)->estado($sesion->parqueadero_id),
        ], 201);
    }

    /**
     * Ubica la sesion abierta SIN cobrarla.
     *
     * Es la pantalla de busqueda del mostrador: el operador escribe la patente
     * y ve que hay adentro, cuanto tiempo lleva y que tarifas rigen esa
     * sede, ANTES de cobrar.
     *
     * Por que se devuelve la lista de tarifas y no el monto: la tarifa se
     * busca por el dia del INGRESO y por el tipo de vehiculo, y ese calculo
     * lo hace `GeneradorDeBoleta` en el momento del cobro. Calcularlo otra
     * vez aca mostraria una cifra que podria no ser la que sale, y en una
     * pantalla de cobro eso es peor que no mostrar nada. Ver docs/06.
     */
    public function buscar(StoreSalidaRequest $request, RegistradorSalida $salidas): JsonResponse
    {
        $sesion = $this->localizar($request, $salidas);

        $tiempo = app(CalculadoraTiempo::class);
        $segundos = $tiempo->segundosHastaAhora($sesion->ingreso_en);

        return response()->json([
            'sesion' => [
                'id' => $sesion->id,
                'patente' => $sesion->patente,
                'vin' => $sesion->vin,
                'lugar_codigo' => $sesion->lugar?->codigo,
                'parqueadero_id' => $sesion->parqueadero_id,
                'ingreso_en' => $sesion->ingreso_en->toIso8601String(),
                'segundos_permanencia' => $segundos,
                'tiempo' => $tiempo->desglosar($segundos),
            ],
            'tarifas_vigentes' => Tarifa::query()
                ->where('parqueadero_id', $sesion->parqueadero_id)
                ->where('activo', true)
                ->orderByDesc('vigente_desde')
                ->get()
                ->map(fn (Tarifa $tarifa): array => [
                    'id' => $tarifa->id,
                    'nombre' => $tarifa->nombre,
                    'tipo' => $tarifa->tipo,
                    'etiqueta' => $tarifa->etiqueta(),
                    'valor' => $tarifa->valor,
                    'minutos_por_fraccion' => $tarifa->minutos_por_fraccion,
                    'completa' => $tarifa->estaCompleta(),
                ]),
        ]);
    }

    /**
     * Reemite la boleta de un estacionamiento que ya salio.
     *
     * El caso real: el cliente perdio el ticket y vuelve al dia siguiente
     * pidiendo que le demuestren que pago. El tiempo sale de la sesion, que lo
     * tiene guardado, asi que la cuenta se rehace tal cual.
     */
    public function reconstruir(
        Estacionamiento $estacionamiento,
        ReconstructorDeBoleta $reconstructor,
    ): JsonResponse {
        try {
            $boleta = $reconstructor->reconstruir(
                $estacionamiento,
                $this->usuario(),
                'efectivo',
            );
        } catch (ErrorDeParqueadero|RuntimeException $error) {
            return response()->json([
                'mensaje' => $error->getMessage(),
                'codigo' => 'reconstruccion_rechazada',
            ], 422);
        }

        return response()->json(['boleta' => $this->presentar($boleta)], 201);
    }

    /**
     * Ubica la sesion por los tres caminos admitidos.
     */
    private function localizar(StoreSalidaRequest $request, RegistradorSalida $salidas): Estacionamiento
    {
        $idSesion = $request->validated('estacionamiento_id');

        if ($idSesion !== null) {
            $sesion = Estacionamiento::query()->find($idSesion);

            // 404 y no 403: la respuesta no confirma si el id existe en otro
            // operador. Ver docs/03-MULTITENANCY-Y-ROLES.md.
            if ($sesion === null) {
                abort(404, 'Ese estacionamiento no existe en este operador.');
            }

            return $sesion->load('lugar', 'vehiculo');
        }

        try {
            $busqueda = $salidas->buscarSesionAbierta(
                $request->user()->tenant_id,
                (string) $request->validated('patente'),
                (string) $request->validated('vin'),
            );
        } catch (RuntimeException $error) {
            abort(404, $error->getMessage());
        }

        return $busqueda['sesion']->load('lugar', 'vehiculo');
    }

    private function usuario(): Usuario
    {
        $usuario = request()->user();

        if (! $usuario instanceof Usuario) {
            abort(401);
        }

        return $usuario;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Boleta $boleta): array
    {
        return [
            'id' => $boleta->id,
            'folio' => $boleta->folio,
            'emitida_en' => $boleta->emitida_en->toIso8601String(),
            'subtotal' => $boleta->subtotal,
            'descuento' => $boleta->descuento,
            'total' => $boleta->total,
            'forma_pago' => $boleta->forma_pago,
            'estado' => $boleta->estado,
            'desglose' => $boleta->desglose,
            'estacionamiento_id' => $boleta->estacionamiento_id,
            'patente' => $boleta->estacionamiento?->patente,
        ];
    }
}