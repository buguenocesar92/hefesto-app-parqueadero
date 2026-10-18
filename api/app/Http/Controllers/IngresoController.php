<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreIngresoRequest;
use App\Services\Estacionamiento\CalculadoraOcupacion;
use App\Services\Estacionamiento\RegistradorIngreso;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * Ingreso de vehiculos: el boton que mas se aprieta del sistema.
 *
 * QUE HACE ESTE CONTROLADOR Y QUE HACE EL SERVICIO
 * -------------------------------------------------
 * Aqui no hay reglas de negocio. Se validan los datos que llegan, se
 * identifica a la persona y se llama a `RegistradorIngreso`, que es el que
 * conoce el indice unico parcial, la creacion del vehiculo y el aviso de
 * mensualidad. Repartir esas reglas entre un controlador y un servicio hace
 * que la transaccion este partida en dos lugares.
 *
 * EL CHOQUE DE LUGAR NO ES UN ERROR
 * ---------------------------------
 * Dos operarios pueden querer el mismo lugar en el mismo instante. El
 * servicio lo detecta por el indice unico y devuelve un mensaje util con el
 * lugar libre mas cercano. Acoplo se traduce a un 422 con codigo estable, no
 * a un 500: para el operador es una situacion normal, no una falla.
 */
class IngresoController extends Controller
{
    public function store(StoreIngresoRequest $request, RegistradorIngreso $registrador): JsonResponse
    {
        try {
            $sesion = $registrador->registrar($request->user(), $request->validated());
        } catch (RuntimeException $error) {
            // Los servicios que ya estaban en el repositorio lanzan la
            // clase base `RuntimeException`, no una excepcion propia. Por eso
            // se captura esta y no `ErrorDeParqueadero`.
            return response()->json([
                'mensaje' => $error->getMessage(),
                'codigo' => 'ingreso_rechazado',
            ], 422);
        }

        $ocupacion = app(CalculadoraOcupacion::class)->estado($sesion->parqueadero_id);

        return response()->json([
            'sesion' => [
                'id' => $sesion->id,
                'patente' => $sesion->patente,
                'vin' => $sesion->vin,
                'lugar_codigo' => $sesion->lugar?->codigo,
                'lugar_tipo' => $sesion->lugar?->tipo,
                'parqueadero_id' => $sesion->parqueadero_id,
                'ingreso_en' => $sesion->ingreso_en->toIso8601String(),
                'referencia_ticket' => $registrador->referenciaTicket($sesion->vin),
            ],
            'ocupacion' => $ocupacion,
        ], 201);
    }

    /**
     * Revision de acceso antes de marcar el ingreso.
     *
     * Se llama cuando el operador escribe la patente: el sistema responde si
     * el vehiculo es mensualista, si tiene la credencial al dia y si hay algo
     * que avisar. Es una AYUDA, no un bloqueo: el ingreso se registra igual.
     * Ver docs/06-INGRESO-VEHICULO.md.
     */
    public function revisar(StoreIngresoRequest $request, RegistradorIngreso $registrador): JsonResponse
    {
        $revision = $registrador->revisarAcceso(
            $request->user()->tenant_id,
            strtoupper((string) $request->input('vin')),
        );

        return response()->json($revision);
    }
}