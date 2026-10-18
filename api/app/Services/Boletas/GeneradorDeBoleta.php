<?php

declare(strict_types=1);

namespace App\Services\Boletas;

use App\Models\Boleta;
use App\Models\Estacionamiento;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Services\Estacionamiento\CalculadoraTarifa;
use App\Services\Estacionamiento\RelojDelServidor;
use RuntimeException;

/**
 * Emision de la boleta.
 *
 * TODO EL CALCULO ES DEL SERVIDOR
 * ------------------------------
 * El metodo `emitir()` recibe identificadores, no dinero. El tiempo sale de
 * la sesion, la tarifa sale de la base y el monto lo arma
 * `CalculadoraTarifa`. No hay ninguna ruta por la que un valor enviado por el
 * cliente llegue a la columna `total`.
 *
 * EL FOLIO
 * --------
 * El folio es unico por operador, no global: dos sedes pueden emitir
 * `B-000001` el mismo dia sin tocarse. Se genera dentro de la transaccion
 * de la boleta y la unicidad la impone el indice `unico_folio_por_operador`.
 * Si dos operarios emiten a la vez, uno reintenta con el siguiente numero.
 */
class GeneradorDeBoleta
{
    public function __construct(private readonly CalculadoraTarifa $calculadora) {}

    public function emitir(
        Estacionamiento $sesion,
        Usuario $operador,
        Tarifa $tarifa,
        int $segundosPermanencia,
        string $formaPago = 'efectivo',
        int $descuento = 0
    ): Boleta {
        $calculo = $this->calculadora->calcular($tarifa, $segundosPermanencia);

        $subtotal = (int) $calculo['subtotal'];

        if ($descuento < 0) {
            throw new RuntimeException('El descuento no puede ser negativo.');
        }

        if ($descuento > $subtotal) {
            throw new RuntimeException(
                'El descuento (' . $descuento . ') no puede ser mayor que el total (' . $subtotal . ').'
            );
        }

        $total = $subtotal - $descuento;

        $folio = $this->generarFolio($sesion->tenant_id, $sesion->parqueadero_id);

        $boleta = Boleta::query()->create([
            'tenant_id' => $sesion->tenant_id,
            'parqueadero_id' => $sesion->parqueadero_id,
            'estacionamiento_id' => $sesion->id,
            'operador_id' => $operador->id,
            'folio' => $folio,
            'emitida_en' => RelojDelServidor::ahora(),
            // El desglose completo va a la base. Es lo que permite
            // reconstruir y explicar una boleta meses despues.
            'desglose' => array_merge($calculo['detalle'], [
                'segundos_permanencia' => $segundosPermanencia,
                'descuento' => $descuento,
                'total' => $total,
            ]),
            'subtotal' => $subtotal,
            'total' => $total,
            'descuento' => $descuento,
            'forma_pago' => $formaPago,
            'estado' => 'emitida',
        ]);

        return $boleta;
    }

    /**
     * Emite el mismo cobro desde el registro, sin sesion abierta.
     *
     * Es el camino del cliente que perdio el ticket. La sesion ya esta
     * cerrada y tiene `segundos_permanencia` guardado, asi que el calculo
     * no necesita la hora actual: se reconstruye desde el registro, que es
     * justamente lo que el modulo de boletas promete.
     */
    public function reconstruirDesdeRegistro(
        Estacionamiento $sesion,
        Usuario $operador,
        Tarifa $tarifa,
        string $formaPago = 'efectivo'
    ): Boleta {
        if ($sesion->salida_en === null) {
            throw new RuntimeException(
                'No se puede reconstruir una boleta de un estacionamiento que sigue abierto.'
            );
        }

        if ($sesion->segundos_permanencia === null) {
            throw new RuntimeException(
                'El estacionamiento ' . $sesion->id . ' no tiene tiempo de permanencia registrado, '
                . 'asi que no hay nada que reconstruir. Revise el registro de movimientos.'
            );
        }

        // El tiempo se recalcula desde las marcas de la base y no se
        // confía en el valor guardado: si el registro fue corregido, el
        // recalculo da el valor correcto.
        $segundos = $sesion->salida_en->getTimestamp() - $sesion->ingreso_en->getTimestamp();

        return $this->emitir(
            sesion: $sesion,
            operador: $operador,
            tarifa: $tarifa,
            segundosPermanencia: $segundos,
            formaPago: $formaPago
        );
    }

    /**
     * Folio con la forma PREFIJO-000001.
     *
     * El prefijo sale de la sede, para que el operario de una sede no tenga
     * que llamar al de otra cuando hay dos con el mismo numero.
     */
    private function generarFolio(string $tenantId, string $parqueaderoId): string
    {
        $ultima = Boleta::query()
            ->where('tenant_id', $tenantId)
            ->where('parqueadero_id', $parqueaderoId)
            ->orderByDesc('emitida_en')
            ->orderByDesc('folio')
            ->value('folio');

        $siguiente = 1;

        if ($ultima !== null && preg_match('/(\d+)$/', (string) $ultima, $coincidencias) === 1) {
            $siguiente = ((int) $coincidencias[1]) + 1;
        }

        $prefijo = substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $parqueaderoId)), 0, 6);

        return $prefijo . '-' . str_pad((string) $siguiente, 6, '0', STR_PAD_LEFT);
    }
}
