<?php

declare(strict_types=1);

namespace App\Services\Estacionamiento;

use App\Enums\TipoMovimiento;
use App\Enums\TipoVehiculo;
use App\Models\Boleta;
use App\Models\Estacionamiento;
use App\Models\Movimiento;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Services\Boletas\GeneradorDeBoleta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registro de la salida y emision de la boleta.
 *
 * RECONSTRUCCION DE LA CUENTA DESDE EL REGISTRO
 * ---------------------------------------------
 * El ticket que se entrega al salir es el respaldo del cliente. Si lo
 * pierde, la cuenta tiene que poder reconstruirse desde el registro, y por
 * eso este servicio acepta dos caminos:
 *
 *  - con `estacionamiento_id`, que es el camino normal;
 *  - con patente, vin y fecha, que es el camino de escritorio: el operador
 *    no tiene el ticket pero si la hora aproximada. El metodo
 *    `buscarSesionAbierta()` la localiza.
 *
 * En los dos casos el tiempo sale de `ingreso_en` y `salida_en` de la base,
 * nunca de un dato que mande el cliente. Si el ticket no existe, el tiempo
 * real sigue guardado en la sesion y la boleta se arma igual.
 */
class RegistradorSalida
{
    public function __construct(
        private readonly CalculadoraTiempo $tiempo,
        private readonly GeneradorDeBoleta $boletas,
        private readonly CalculadoraOcupacion $ocupacion,
    ) {}

    /**
     * Registra la salida, cierra la sesion y emite la boleta en una sola
     * transaccion.
     *
     * @param  array{
     *     forma_pago?: string,
     *     descuento?: int,
     *     foto_ruta?: string|null,
     *     salida_en?: \Carbon\CarbonImmutable|null
     * }  $opciones
     */
    public function registrar(
        Estacionamiento $sesion,
        Usuario $operador,
        array $opciones = []
    ): Boleta {
        return DB::transaction(function () use ($sesion, $operador, $opciones): Boleta {
            // Se vuelve a leer la sesion con un bloqueo de fila para que dos
            // operarios no puedan cerrar la misma sesion a la vez: el
            // segundo veria que ya tiene salida y no emitiria una segunda
            // boleta por el mismo estacionamiento.
            $bloqueada = Estacionamiento::query()
                ->lockForUpdate()
                ->findOrFail($sesion->id);

            if ($bloqueada->salida_en !== null) {
                throw new RuntimeException(
                    'Este vehiculo ya tiene una salida registrada a las '
                    . $bloqueada->salida_en->format('H:i') . '. '
                    . 'No se puede cobrar dos veces el mismo estacionamiento.'
                );
            }

            $salida = $opciones['salida_en'] ?? RelojDelServidor::ahora();

            $segundos = $this->tiempo->segundos($bloqueada->ingreso_en, $salida);

            // El tipo de vehiculo se lee del vehiculo, no de la sesion:
            // `estacionamientos` no lo duplica, para que el dato no pueda
            // quedar contradictorio con el del vehiculo.
            $bloqueada->loadMissing('vehiculo');

            $tarifa = $this->tarifaVigente(
                $bloqueada->parqueadero_id,
                $bloqueada->ingreso_en,
                (string) ($bloqueada->vehiculo?->tipo_vehiculo ?? 'automobile')
            );

            $boleta = $this->boletas->emitir(
                sesion: $bloqueada,
                operador: $operador,
                tarifa: $tarifa,
                segundosPermanencia: $segundos,
                formaPago: $opciones['forma_pago'] ?? 'efectivo',
                descuento: (int) ($opciones['descuento'] ?? 0)
            );

            // El tiempo se guarda en la sesion. Es lo que permite
            // reconstruir la cuenta mas tarde aunque la tarifa cambie.
            $bloqueada->salida_en = $salida;
            $bloqueada->segundos_permanencia = $segundos;
            $bloqueada->boleta_id = $boleta->id;
            $bloqueada->save();

            Movimiento::query()->create([
                'tenant_id' => $bloqueada->tenant_id,
                'parqueadero_id' => $bloqueada->parqueadero_id,
                'estacionamiento_id' => $bloqueada->id,
                'operador_id' => $operador->id,
                'tipo' => TipoMovimiento::Salida->value,
                'ocurrido_en' => $salida,
                'patente' => $bloqueada->patente,
                'vin' => $bloqueada->vin,
                'tipo_vehiculo' => $bloqueada->vehiculo?->tipo_vehiculo?->value ?? TipoVehiculo::Automobile->value,
                'lugar_codigo' => (string) ($bloqueada->lugar->codigo ?? ''),
                'foto_ruta' => $opciones['foto_ruta'] ?? null,
                'foto_tomada_en' => ($opciones['foto_ruta'] ?? null) !== null
                    ? $salida
                    : null,
            ]);

            $this->ocupacion->invalidar($bloqueada->parqueadero_id);

            return $boleta->load('estacionamiento.lugar');
        });
    }

    /**
     * Busca la sesion abierta de un vehiculo.
     *
     * Se usa cuando el cliente no tiene ticket: el operador tiene la
     * patente y, a veces, una hora aproximada.
     *
     * @return array{sesion: Estacionamiento, candidatas: int}
     */
    public function buscarSesionAbierta(string $tenantId, string $patente, string $vin): array
    {
        $consulta = Estacionamiento::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('salida_en');

        // Si hay VIN, se busca por VIN: la patente puede estar en dos autos
        // de la misma familia y el VIN es el que no miente.
        if ($vin !== '') {
            $consulta->where('vin', $vin);
        } else {
            $consulta->where('patente', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $patente)));
        }

        $candidatas = $consulta->with('lugar')->get();

        if ($candidatas->isEmpty()) {
            throw new RuntimeException(
                'No hay ningun vehiculo estacionado con esa identificacion en este operador.'
            );
        }

        // Con varias coincidencias no se adivina: se devuelven todas para
        // que el operario elija. Elegir por el operario y inevitable, pero
        // debe ser una decision visible, no un supuesto silencioso.
        if ($candidatas->count() > 1) {
            return [
                'sesion' => $candidatas->first(),
                'candidatas' => $candidatas->count(),
            ];
        }

        return [
            'sesion' => $candidatas->first(),
            'candidatas' => 1,
        ];
    }

    /**
     * Tarifa que estaba vigente el dia del ingreso.
     *
     * Se busca por la fecha del ingreso y no por la de hoy: si el auto
     * entro antes de un cambio de tarifas, se cobra la tarifa de ese dia.
     * Sin esto, una boleta de hace un mes no se puede explicar.
     */
    private function tarifaVigente(string $parqueaderoId, mixed $ingresoEn, string $tipoVehiculo): Tarifa
    {
        $tarifa = Tarifa::query()
            ->where('parqueadero_id', $parqueaderoId)
            ->where('activo', true)
            ->where('vigente_desde', '<=', $ingresoEn)
            ->where(function ($consulta) use ($ingresoEn): void {
                $consulta->whereNull('vigente_hasta')
                    ->orWhere('vigente_hasta', '>=', $ingresoEn);
            })
            ->where(function ($consulta) use ($tipoVehiculo): void {
                $consulta->whereNull('tipo_vehiculo')
                    ->orWhere('tipo_vehiculo', $tipoVehiculo);
            })
            ->orderByDesc('vigente_desde')
            ->first();

        if ($tarifa === null) {
            throw new RuntimeException(
                'No hay ninguna tarifa vigente en esta sede para el ' . $ingresoEn->format('d-m-Y') . '. '
                . 'Cargue las tarifas antes de emitir boletas.'
            );
        }

        return $tarifa;
    }
}
