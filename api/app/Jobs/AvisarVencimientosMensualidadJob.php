<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bitacora;
use App\Models\Mensualidad;
use App\Models\Usuario;
use App\Services\Estacionamiento\RelojDelServidor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Avisa que mensualidades estan por vencer o vencidas.
 *
 * QUE HACE Y QUE NO HACE
 * ----------------------
 * Escribe en la bitacora. NO manda correos ni mensajes: este sistema no tiene
 * envio de correo configurado, y escribir codigo que finge mandar un correo es
 * peor que no mandarlo.
 *
 * Y sobre todo NO termina nada. Una mensualidad no se termina sola por que
 * paso la fecha: eso lo decide una persona. Este trabajo deja la lista de lo
 * que hay que revisar, que es exactamente para lo que existe la columna
 * `dias_aviso_vencimiento` y el estado `suspendida`.
 *
 * POR QUE VA EN COLA
 * ------------------
 * Corre una vez al dia y, cuando el operador tiene muchas sedes, escribe
 * varias filas de bitacora. No es un trabajo que deba bloquear la peticion
 * que lo lanzo.
 *
 * OJO CON EL CONTEXTO DE OPERADOR
 * ------------------------------
 * Este trabajo lee `mensualidades`, que tiene RLS. Por eso recibe el
 * `tenant_id` y fija el contexto con `ContextoTenant::ejecutarAislado()`:
 * la conexion del worker es compartida entre trabajos de operadores
 * distintos, y sin ese paso el trabajo no veria filas o veria las de otro.
 */
class AvisarVencimientosMensualidadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId) {}

    public function handle(): void
    {
        $contexto = app(\App\Services\Tenancy\ContextoTenant::class);

        $contexto->ejecutarAislado($this->tenantId, function (): void {
            $this->escribirAvisos();
        });
    }

    private function escribirAvisos(): void
    {
        $hoy = RelojDelServidor::ahora();
        $dias = $this->diasDeAviso();

        $limite = $hoy->addDays($dias)->toDateString();

        $mensualidades = Mensualidad::query()
            ->where('estado', Mensualidad::ESTADO_ACTIVA)
            ->where('credencial_vence_en', '<=', $limite)
            ->with('vehiculo')
            ->orderBy('credencial_vence_en')
            ->limit(500)
            ->get();

        foreach ($mensualidades as $mensualidad) {
            $diasParaVencer = (int) $hoy->diffInDays(
                $mensualidad->credencial_vence_en->startOfDay(),
                false
            );

            $estado = $diasParaVencer < 0
                ? 'Vencida hace ' . abs($diasParaVencer) . ' dia(s).'
                : ($diasParaVencer === 0
                    ? 'Vence hoy.'
                    : 'Vence en ' . $diasParaVencer . ' dia(s).');

            // El usuario es nulo a proposito: este aviso lo genera el sistema,
            // no una persona. Queda asi, y no se inventa.
            Bitacora::query()->create([
                'tenant_id' => $this->tenantId,
                'usuario_id' => null,
                'ambito' => 'mensualidades',
                'accion' => 'vencimiento',
                'recurso_tipo' => Mensualidad::class,
                'recurso_id' => $mensualidad->id,
                'descripcion' => sprintf(
                    'Mensualidad del vehiculo %s: %s',
                    (string) $mensualidad->vehiculo?->patente,
                    $estado
                ),
                'datos' => [
                    'vehiculo_id' => $mensualidad->vehiculo_id,
                    'dias_para_vencer' => $diasParaVencer,
                    'monto_mensual' => $mensualidad->monto_mensual,
                ],
                'direccion_ip' => null,
                'creado_en' => $hoy->toDateTime(),
            ]);
        }
    }

    /**
     * Ventana de aviso del operador, con un valor por omision acotado.
     *
     * La columna es `unsignedSmallInteger` y no tiene limite superior en la
     * base, asi que el dato viene de la pantalla. Un valor absurdo haria que
     * el trabajo revisara todas las mensualidades del operador.
     */
    private function diasDeAviso(): int
    {
        $dias = (int) config('parqueadero.dias_aviso_vencimiento', 15);

        return max(1, min(90, $dias));
    }
}