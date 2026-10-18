<?php

declare(strict_types=1);

namespace App\Services\Estacionamiento;

use App\Enums\TipoMovimiento;
use App\Enums\TipoVehiculo;
use App\Models\Estacionamiento;
use App\Models\Lugar;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Services\Mensualidades\VerificadorDeAcceso;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Registro del ingreso de un vehiculo.
 *
 * EL ORDEN DE LAS OPERACIONES ES EL QUE IMPORTA
 * ----------------------------------------------
 * Todo pasa dentro de una sola transaccion, y el orden es:
 *
 *  1. Buscar o crear el vehiculo por VIN.
 *  2. Insertar la sesion en `estacionamientos`.
 *  3. Insertar el movimiento de tipo `ingreso`.
 *  4. Subir la version de la cache de ocupacion.
 *
 * Los pasos 2 y 3 estan juntos a proposito: si la sesion se inserta y el
 * movimiento no, queda un vehiculo adentro del registro que nadie puede
 * ver y que el conteo de lugares tampoco puede explicar.
 *
 * LA COLISION DE LUGAR
 * -------------------
 * El paso 2 puede fallar por violacion del indice unico parcial
 * `unico_lugar_sesion_abierta`. Eso no es un error del sistema: es otro
 * operario que entró primero. Se traduce a un mensaje util y se ofrece el
 * lugar libre mas cercano. La transaccion se revierte entera, asi que no
 * queda ni vehiculo huerfano ni movimiento sin sesion.
 */
class RegistradorIngreso
{
    public function __construct(
        private readonly CalculadoraOcupacion $ocupacion,
        private readonly VerificadorDeAcceso $mensualidades,
    ) {}

    /**
     * @param  array{
     *     patente: string,
     *     vin: string,
     *     tipo_vehiculo: string,
     *     lugar_id: string,
     *     foto_ruta?: string|null,
     *     color?: string|null,
     *     marca?: string|null,
     *     modelo?: string|null
     * }  $datos
     */
    public function registrar(Usuario $operador, array $datos): Estacionamiento
    {
        $patente = $this->normalizarPatente($datos['patente']);
        $vin = $this->normalizarVin($datos['vin']);

        // El lugar se resuelve ANTES de la transaccion, no dentro. Asi el
        // manejador de colisiones sabe a que sede pertenece el intento y
        // puede ofrecer un lugar libre concreto. Resolverlo dentro dejaria
        // el manejador sin esa informacion.
        $lugar = Lugar::query()->findOrFail($datos['lugar_id']);

        try {
            return DB::transaction(function () use ($operador, $datos, $patente, $vin, $lugar): Estacionamiento {
                $vehiculo = $this->obtenerOCrearVehiculo($operador->tenant_id, $patente, $vin, $datos);

                // El VIN del vehiculo y el de la sesion tienen que ser el
                // mismo. Se copian en la misma transaccion, asi que no
                // pueden quedar incoherentes.
                $sesion = Estacionamiento::query()->create([
                    'tenant_id' => $operador->tenant_id,
                    'parqueadero_id' => $lugar->parqueadero_id,
                    'vehiculo_id' => $vehiculo->id,
                    'lugar_id' => $lugar->id,
                    'patente' => $patente,
                    'vin' => $vin,
                    'ingreso_en' => RelojDelServidor::ahora(),
                ]);

                Movimiento::query()->create([
                    'tenant_id' => $operador->tenant_id,
                    'parqueadero_id' => $lugar->parqueadero_id,
                    'estacionamiento_id' => $sesion->id,
                    'operador_id' => $operador->id,
                    'tipo' => TipoMovimiento::Ingreso->value,
                    'ocurrido_en' => $sesion->ingreso_en,
                    'patente' => $patente,
                    'vin' => $vin,
                    'tipo_vehiculo' => $vehiculo->tipo_vehiculo,
                    'lugar_codigo' => $lugar->codigo,
                    'foto_ruta' => $datos['foto_ruta'] ?? null,
                    'foto_tomada_en' => ($datos['foto_ruta'] ?? null) !== null
                        ? RelojDelServidor::ahora()
                        : null,
                ]);

                // La cache se invalida dentro de la transaccion. Si la
                // transaccion revierte, la version no cambio y la cache
                // sigue siendo correcta.
                $this->ocupacion->invalidar($lugar->parqueadero_id);

                return $sesion->load('lugar', 'vehiculo');
            });
        } catch (QueryException $error) {
            throw $this->traducirChoqueDeLugar($error, $lugar->parqueadero_id, $lugar->codigo);
        }
    }

    /**
     * Dice si el ingreso de este vehiculo merece un aviso.
     *
     * No bloquea: la decision de detener o dejar pasar la toma una persona.
     * Ver la advertencia del README.
     *
     * @return array{acceso: string, avisos: list<string>}
     */
    public function revisarAcceso(string $tenantId, string $vin): array
    {
        return $this->mensualidades->revisar($tenantId, $vin);
    }

    /**
     * Busca el vehiculo por VIN, o lo crea si no existe.
     *
     * Se busca por VIN y no por patente a proposito: dos vehiculos de la
     * misma familia comparten patente, y el que llego segundo es uno nuevo,
     * no el mismo auto. Ver migracion 2026_10_17_000007.
     */
    private function obtenerOCrearVehiculo(
        string $tenantId,
        string $patente,
        string $vin,
        array $datos
    ): Vehiculo {
        $existente = Vehiculo::query()
            ->where('tenant_id', $tenantId)
            ->where('vin', $vin)
            ->first();

        if ($existente !== null) {
            // El VIN manda: la patente se corrige con el dato mas reciente.
            if ($existente->patente !== $patente) {
                $existente->patente = $patente;
                $existente->save();
            }

            return $existente;
        }

        $tipo = TipoVehiculo::from($datos['tipo_vehiculo'] ?? 'automobile');

        return Vehiculo::query()->create([
            'tenant_id' => $tenantId,
            'patente' => $patente,
            'vin' => $vin,
            'tipo_vehiculo' => $tipo->value,
            'color' => $datos['color'] ?? null,
            'marca' => $datos['marca'] ?? null,
            'modelo' => $datos['modelo'] ?? null,
        ]);
    }

    /**
     * Traduce la violacion del indice unico a un mensaje de operacion.
     *
     * Si el error NO es el indice `unico_lugar_sesion_abierta`, se relanza
     * tal cual: swallowing errores para convenience convertiria un fallo real
     * en un "lugar ocupado" y taparia la causa.
     */
    private function traducirChoqueDeLugar(
        QueryException $error,
        string $parqueaderoId,
        string $codigoIntentado
    ): RuntimeException {
        $codigoSql = (string) ($error->errorInfo[0] ?? '');

        // 23505 es unique_violation en PostgreSQL.
        if ($codigoSql !== '23505') {
            return new RuntimeException(
                'No se pudo registrar el ingreso por un error de base de datos: ' . $error->getMessage(),
                0,
                $error
            );
        }

        $sugerido = $this->ocupacion->lugarLibreMasCercano($parqueaderoId, $codigoIntentado);

        $mensaje = 'Ese lugar fue tomado mientras se confirmaba el ingreso.';

        if ($sugerido !== null) {
            $mensaje .= ' El siguiente lugar libre es ' . $sugerido['codigo'] . '.';
        } else {
            $mensaje .= ' No quedan lugares libres en esta sede.';
        }

        return new RuntimeException($mensaje, 0, $error);
    }

    /**
     * Normaliza la patente: sin espacios, sin guiones, en mayusculas.
     *
     * "AB CD-12" y "ABCD12" son la misma patente. Si no se normalizan, el
     * mismo auto aparece dos veces en el registro.
     */
    private function normalizarPatente(string $patente): string
    {
        $limpia = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $patente));

        if ($limpia === '') {
            throw new RuntimeException('La patente no puede quedar vacia.');
        }

        return $limpia;
    }

    /**
     * Normaliza el VIN: 17 caracteres alfanumericos en mayusculas.
     */
    private function normalizarVin(string $vin): string
    {
        $limpio = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $vin));

        // Se acepta menos de 17 caracteres porque hay vehiculos antiguos
        // y placas importadas sin VIN completo. Lo que no se acepta es un
        // VIN con caracteres que no son alfanumericos.
        if (strlen($limpio) < 6 || strlen($limpio) > 17) {
            throw new RuntimeException(
                'El VIN debe tener entre 6 y 17 caracteres alfanumericos. '
                . 'Se recibio: ' . strlen($limpio) . ' caracteres.'
            );
        }

        return $limpio;
    }

    /**
     * Referencia corta para el ticket: los ultimos caracteres del VIN.
     */
    public function referenciaTicket(string $vin): string
    {
        return Str::upper(substr($vin, -6));
    }
}
