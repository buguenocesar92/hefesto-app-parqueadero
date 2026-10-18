<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AvisarVencimientosMensualidadJob;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * `parqueadero:avisar-mensualidades`
 *
 * Lanza `AvisarVencimientosMensualidadJob` para cada operador activo.
 *
 * POR QUE ES UN COMANDO Y NO UN PLANIFICADOR DENTRO DE LA APLICACION
 * --------------------------------------------------------------
 * El planificador de Laravel corre dentro del contenedor de la API. Si el
 * contenedor se reinicia tres veces en la noche, el aviso corre tres veces.
 * Con un comando disparado desde el sistema y con su propia entrada en
 * crontab, correr una vez es decision de quien programa, no efecto colateral
 * de un reinicio.
 *
 * `--directo` corre el trabajo en el proceso, para la instalacion chica que
 * no tiene worker de colas levantado.
 */
class ParqueaderoAvisarMensualidades extends Command
{
    protected $signature = 'parqueadero:avisar-mensualidades
                            {--directo : Ejecutar sin cola, para instalaciones sin worker}';

    protected $description = 'Deja en bitacora las mensualidades vencidas y proximas a vencer.';

    public function handle(): int
    {
        // `tenants` no tiene RLS: se lee sin contexto.
        $operadores = Tenant::query()->where('activo', true)->get();

        if ($operadores->isEmpty()) {
            $this->warn('No hay operadores activos.');

            return self::SUCCESS;
        }

        $directo = $this->option('directo') === true;
        $total = 0;

        foreach ($operadores as $operador) {
            $trabajo = new AvisarVencimientosMensualidadJob($operador->id);

            if ($directo) {
                $trabajo->handle();
            } else {
                dispatch($trabajo);
            }

            $total++;
        }

        $this->info(sprintf(
            '%d aviso(s) de vencimiento%s.',
            $total,
            $directo ? ' (ejecutados en este proceso)' : ' (encolados)'
        ));

        return self::SUCCESS;
    }
}