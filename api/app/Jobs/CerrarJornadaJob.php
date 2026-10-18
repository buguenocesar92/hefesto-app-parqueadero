<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Auditoria\RegistradorDeBitacora;
use App\Services\Estacionamiento\CalculadoraTarifa;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Cierra la jornada de una sede y deja la cuenta escrita.
 *
 * POR QUE EXISTE
 * --------------
 * El total del dia se puede pedir en cualquier momento, pero queda en
 * pantalla. Al cierre del turno, la cuenta del operador queda en la bitacora
 * con su fecha: eso es lo que permite comparar dos dias, detectar un dia
 *raro y saber que se cobro, sin depender de que alguien lo mire a tiempo.
 *
 * QUE ESCRIBE
 * -----------
 * Cantidad de boletas y total, tal como los calcula
 * `CalculadoraTarifa::totalDelDia()`, que suma en PostgreSQL. Solo boletas
 * EMITIDAS: una anulada no se sumo y no tiene que aparecer en el total, pero
 * queda en su propia bitacora por separado.
 *
 * NO RECONCILA CONTRA CAJA
 * ------------------------
 * Este trabajo no sabe cuanto dinero hay en la gaveta. Solo sabe cuanto dice
 * el sistema que se cobro. La diferencia entre las dos cosas la ve una
 * persona al cierre, y esa diferencia es la que hay que mirar.
 */
class CerrarJornadaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $parqueaderoId,
        public readonly string $fecha,
    ) {}

    public function handle(RegistradorDeBitacora $bitacora, CalculadoraTarifa $tarifas): void
    {
        $contexto = app(ContextoTenant::class);

        $contexto->ejecutarAislado($this->tenantId, function () use ($bitacora, $tarifas): void {
            $inicio = Carbon::parse($this->fecha)->startOfDay();
            $fin = Carbon::parse($this->fecha)->endOfDay();

            $total = $tarifas->totalDelDia(
                $this->parqueaderoId,
                $inicio->toDateTimeString(),
                $fin->toDateTimeString(),
            );

            // Una jornada sin boletas no es un evento que anotar: se anotaria
            // un cero todas las noches en una sede cerrada.
            if ($total['boletas'] === 0) {
                return;
            }

            $bitacora->registrar(
                tenantId: $this->tenantId,
                usuario: null,
                ambito: 'jornada',
                accion: 'cierre',
                recursoTipo: 'Parqueadero',
                recursoId: $this->parqueaderoId,
                descripcion: sprintf(
                    'Cierre del %s: %d boleta(s), $%d.',
                    $this->fecha,
                    $total['boletas'],
                    $total['total']
                ),
                datos: [
                    'fecha' => $this->fecha,
                    'parqueadero_id' => $this->parqueaderoId,
                    'boletas' => $total['boletas'],
                    'total' => $total['total'],
                ],
            );
        });
    }
}