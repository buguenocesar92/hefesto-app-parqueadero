<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AvisarVencimientosMensualidadJob;
use App\Jobs\CerrarJornadaJob;
use App\Models\Parqueadero;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `parqueadero:cerrar-jornada`
 *
 * Lanza el cierre del dia de cada sede activa.
 *
 * SE LANZA, NO HACE
 * -----------------
 * El trabajo real va en la cola, en `CerrarJornadaJob`. Este comando solo lo
 * encola. La razon es que el cierre escribe en bitacora de cada operador, y
 * hacerlo dentro de la transaccion de la lectura de `tenants` seria mixing
 * contexts: la lectura de operadores es SIN contexto (tabla sin RLS) y la
 * escritura es CON contexto.
 *
 * `--directo` existe para cuando no hay worker de colas levantado, que es el
 * caso normal en una instalacion chica de un solo operador. Ahi ejecuta el
 * trabajo en el proceso, operador por operador, cada uno con su contexto.
 *
 * QUE FECHA CIERRA
 * ----------------
 * Por omision, HOY. Con `--fecha`, la que se le pida. Cerrar un dia pasado
 * mas de una vez no rompe nada: escribe otra entrada de bitacora con la misma
 * cuenta, que es un historial auditable de que alguien lo reviso.
 */
class ParqueaderoCerrarJornada extends Command
{
    protected $signature = 'parqueadero:cerrar-jornada
                            {--fecha= : Fecha a cerrar en formato AAAA-MM-DD}
                            {--directo : Ejecutar sin cola, para instalaciones sin worker}';

    protected $description = 'Encola el cierre de la jornada de todas las sedes activas.';

    public function handle(): int
    {
        $fecha = $this->option('fecha');

        if (is_string($fecha) && $fecha !== '') {
            try {
                Carbon::parse($fecha);
            } catch (Throwable) {
                $this->error(sprintf('La fecha "%s" no se entiende. Use el formato AAAA-MM-DD.', $fecha));

                return self::INVALID;
            }
        } else {
            $fecha = Carbon::now()->toDateString();
        }

        // `tenants` no tiene RLS: se lee sin contexto.
        $operadores = Tenant::query()->where('activo', true)->get();

        if ($operadores->isEmpty()) {
            $this->warn('No hay operadores activos.');

            return self::SUCCESS;
        }

        $directo = $this->option('directo') === true;

        $encolados = 0;

        foreach ($operadores as $operador) {
            $sedes = Parqueadero::query()
                ->where('tenant_id', $operador->id)
                ->where('activo', true)
                ->get();

            if ($sedes->isEmpty()) {
                continue;
            }

            foreach ($sedes as $sede) {
                $trabajo = new CerrarJornadaJob($operador->id, $sede->id, $fecha);

                if ($directo) {
                    $this->ejecutarDirecto($trabajo);
                } else {
                    dispatch($trabajo);
                }

                $encolados++;
            }
        }

        $this->info(sprintf(
            '%d cierre(s) para el %s%s.',
            $encolados,
            $fecha,
            $directo ? ' (ejecutados en este proceso)' : ' (encolados)'
        ));

        return self::SUCCESS;
    }

    private function ejecutarDirecto(CerrarJornadaJob $trabajo): void
    {
        // Se ejecuta el metodo del trabajo, que ya fija el contexto del
        // operador con su propia transaccion.
        $trabajo->handle(
            app(\App\Services\Auditoria\RegistradorDeBitacora::class),
            app(\App\Services\Estacionamiento\CalculadoraTarifa::class),
        );
    }
}