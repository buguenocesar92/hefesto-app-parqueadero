<?php

declare(strict_types=1);

namespace App\Services\Mensualidades;

use App\Models\Configuracion;
use App\Models\Mensualidad;
use App\Services\Estacionamiento\RelojDelServidor;
use Carbon\CarbonImmutable;

/**
 * Reviso del acceso de un mensualista.
 *
 * QUE SIGNIFICA "VENCIDO" ACA
 * --------------------------
 * Hay tres fechas y no son la misma: el fin del contrato, el ultimo pago y
 * el vencimiento de la credencial. Esta clase las separa y dice
 * explicitamente cual de las tres fallo, porque para el operador son tres
 * conversaciones distintas con el cliente:
 *
 *  - **Credencial vencida, contrato vigente, pagado:** el cliente pago y
 *    quedo sin permiso para pasar. Se arregla renovando la credencial. No es
 *    un problema de plata.
 *  - **Contrato terminado:** el acuerdo commercial se acabo. No hay nada
 *    que cobrar y hay que entregar el lugar.
 *  - **Pago atrasado:** el contrato sigue vigente pero no se ha pagado. Aca
 *    si hay plata pendiente.
 *
 * Si las tres fechas se mezclaran en una columna, estos tres casos se verian
 * iguales en la pantalla y el operador terminaria cobrando a quien ya no
 * tiene contrato, o dando acceso a quien no pago.
 *
 * LA REGLA ES DEL OPERADOR, NO DEL CODIGO
 * --------------------------------------
 * Que el vencimiento impida o no el acceso esta en
 * `configuraciones.mensualidad_vencida_bloquea_acceso`. Esta clase lo lee y
 * lo informa. No bloquea a nadie.
 *
 * Y aunque diga `true`, el sistema no cierra la puerta: marca el ingreso y
 * avisa. El porton lo decide una persona. Ver la advertencia del README.
 */
class VerificadorDeAcceso
{
    /**
     * Revisa el acceso de un vehiculo y devuelve el resultado.
     *
     * @return array{
     *     estado: string,
     *     permite_paso: bool,
     *     bloquea: bool,
     *     mensualidad: array<string, mixed>|null,
     *     avisos: list<string>
     * }
     */
    public function revisar(string $tenantId, string $vin): array
    {
        $hoy = RelojDelServidor::ahora()->toImmutable();

        $mensualidad = Mensualidad::query()
            ->where('tenant_id', $tenantId)
            ->where('estado', 'activa')
            ->whereHas('vehiculo', function ($consulta) use ($vin): void {
                $consulta->where('vin', $vin);
            })
            ->orderByDesc('credencial_vence_en')
            ->first();

        // Sin mensualidad no hay nada que revisar: es un visitante.
        if ($mensualidad === null) {
            return [
                'estado' => 'visitante',
                'permite_paso' => true,
                'bloquea' => false,
                'mensualidad' => null,
                'avisos' => [],
            ];
        }

        $motivos = $this->motivosDeVencimiento($mensualidad, $hoy);

        if ($motivos === []) {
            return [
                'estado' => 'al_dia',
                'permite_paso' => true,
                'bloquea' => false,
                'mensualidad' => $this->resumen($mensualidad),
                'avisos' => [],
            ];
        }

        $bloquea = $this->laReglaBloquea($tenantId);

        $avisos = [];

        foreach ($motivos as $motivo) {
            $avisos[] = $motivo['mensaje'];
        }

        if (! $bloquea) {
            $avisos[] = 'Este operador permite el paso de mensualidades vencidas: '
                . 'deje registro de la conversacion con el cliente.';
        }

        return [
            'estado' => 'vencida',
            'permite_paso' => ! $bloquea,
            'bloquea' => $bloquea,
            'mensualidad' => $this->resumen($mensualidad),
            'avisos' => $avisos,
        ];
    }

    /**
     * Lista de mensualidades vencidas o por vencer, para la pantalla de
     * cobranza.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function porCobrar(string $tenantId, int $diasDeAnticipacion = 15): \Illuminate\Support\Collection
    {
        $hoy = RelojDelServidor::ahora()->toImmutable();
        $limite = $hoy->addDays($diasDeAnticipacion);

        return Mensualidad::query()
            ->where('tenant_id', $tenantId)
            ->where('estado', 'activa')
            ->orderBy('credencial_vence_en')
            ->get()
            ->map(function (Mensualidad $mensualidad) use ($hoy, $limite): array {
                $motivos = $this->motivosDeVencimiento($mensualidad, $hoy);

                return [
                    'id' => $mensualidad->id,
                    'cliente_nombre' => $mensualidad->cliente_nombre,
                    'patente' => $mensualidad->vehiculo?->patente,
                    'vencimiento_credencial' => $mensualidad->credencial_vence_en,
                    'ultimo_pago' => $mensualidad->fecha_ultimo_pago,
                    'motivos' => $motivos,
                    'por_vencer' => $mensualidad->credencial_vence_en >= $hoy
                        && $mensualidad->credencial_vence_en <= $limite,
                ];
            })
            ->filter(function (array $fila): bool {
                return $fila['motivos'] !== [] || $fila['por_vencer'];
            })
            ->values();
    }

    /**
     * Por que esta vencida esta mensualidad, separando las tres causas.
     *
     * @return list<array{causa: string, mensaje: string}>
     */
    private function motivosDeVencimiento(Mensualidad $mensualidad, CarbonImmutable $hoy): array
    {
        $motivos = [];

        // 1. Contrato terminado.
        if ($mensualidad->fecha_termino !== null && $mensualidad->fecha_termino < $hoy->toDateString()) {
            $motivos[] = [
                'causa' => 'contrato_terminado',
                'mensaje' => 'El contrato termino el ' . $mensualidad->fecha_termino . '. '
                    . 'No hay nada que cobrar: hay que entregar el lugar.',
            ];
        }

        // 2. Credencial vencida. Es independiente del contrato.
        if ($mensualidad->credencial_vence_en < $hoy->toDateString()) {
            $paga = $mensualidad->fecha_ultimo_pago !== null;

            $motivos[] = [
                'causa' => 'credencial_vencida',
                'mensaje' => 'La credencial vencio el ' . $mensualidad->credencial_vence_en . '. '
                    . ($paga
                        ? 'El cliente esta pagando: lo que falta es renovar la credencial.'
                        : 'No hay registro del ultimo pago.'),
            ];
        }

        // 3. Pago atrasado: hay contrato y credencial, pero no hay pago.
        // Se usa `copy()` porque el cast de fecha devuelve un Carbon
        // mutable: sin la copia, `addMonth()` modificaria el atributo del
        // modelo en memoria y la fecha de inicio quedaria cambiada.
        $primerCobro = $mensualidad->fecha_inicio->copy()->addMonth();

        if (
            $mensualidad->fecha_ultimo_pago === null
            && $primerCobro->lessThan($hoy)
        ) {
            $motivos[] = [
                'causa' => 'pago_pendiente',
                'mensaje' => 'No hay registro de ningun pago desde que empezo el contrato.',
            ];
        }

        return $motivos;
    }

    /**
     * Lee la regla del operador. Por defecto bloquea, pero se lee igual:
     * el valor por defecto solo aplica si la fila no existe.
     */
    private function laReglaBloquea(string $tenantId): bool
    {
        $configuracion = Configuracion::query()
            ->where('tenant_id', $tenantId)
            ->first();

        if ($configuracion === null) {
            return true;
        }

        return (bool) $configuracion->mensualidad_vencida_bloquea_acceso;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(Mensualidad $mensualidad): array
    {
        return [
            'id' => $mensualidad->id,
            'cliente_nombre' => $mensualidad->cliente_nombre,
            'patente' => $mensualidad->vehiculo?->patente,
            'vin' => $mensualidad->vehiculo?->vin,
            'fecha_inicio' => $mensualidad->fecha_inicio,
            'fecha_termino' => $mensualidad->fecha_termino,
            'fecha_ultimo_pago' => $mensualidad->fecha_ultimo_pago,
            'credencial_vence_en' => $mensualidad->credencial_vence_en,
            'estado' => $mensualidad->estado,
        ];
    }
}
