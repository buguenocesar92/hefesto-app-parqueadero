<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Tareas programadas de consola
|--------------------------------------------------------------------------
|
| Este archivo se carga con `->withRouting(commands: ...)` en
| `bootstrap/app.php`.
|
| LO QUE ESTA PROGRAMADO Y POR QUE
| ---------------------------------
|
|  - `parqueadero:verificar-aislamiento` al arrancar el contenedor: lo
|    invoca `deploy/api/docker/entrypoint.sh`. Si la RLS no esta puesta, la
|    API se levanta y queda despidiendo datos de otros operadores. Es
|    preferible que no levante.
|
|  - el resto va con `withoutOverlapping`: dos cierres del mismo operador
|    corriendo a la vez producirian dos entradas de bitacora por el mismo
|    dia.
|
| QUE NO SE PROGRAMA AQUI
| ----------------------
| El envio de avisos por correo. No hay correo configurado y una tarea que
| finge mandar un correo es peor que ninguna. `parqueadero:avisar-mensualidades`
| deja el aviso en bitacora, que es lo que se puede verificar hoy.
|
| QUE NO SE HACE EN UN PLANIFICADOR DENTRO DE LA APLICACION
| ---------------------------------------------------------
| Si el contenedor de la API se reinicia, el planificador corre de nuevo lo
| que le tocaba. Para una tarea diaria, lo correcto es dispararla desde el
| sistema (cron o un temporizador del orquestador) y no confiar en que el
| proceso quede vivo. Ver docs/04-INSTALACION.md.
|
*/

Artisan::command('parqueadero:instalar', function (): void {
    $this->newLine();
    $this->line('  <info>Parqueadero de Hefesto Studio</info>');
    $this->line('  Laravel ' . app()->version());
    $this->newLine();

    $this->line('  <comment>Tareas disponibles de este sistema:</comment>');
    $this->line('    <info>parqueadero:verificar-aislamiento</info>  Comprueba la RLS por operador.');
    $this->line('    <info>parqueadero:verificar-registro</info>      Revisa que las sesiones tengan movimiento.');
    $this->line('    <info>parqueadero:cerrar-jornada</info>         Cierra el dia de cada sede.');
    $this->line('    <info>parqueadero:avisar-mensualidades</info>  Avisa vencimientos en bitacora.');
    $this->newLine();

    $this->line('  ' . Inspiring::quote());
    $this->newLine();
})->purpose('Muestra las tareas propias del parqueadero y su proposito.');

Artisan::command('parqueadero:doc', function (): void {
    $this->line('');
    $this->line('  Documentacion en <info>docs/</info>:');
    $this->line('    01-ARQUITECTURA.md          como esta armado');
    $this->line('    02-MODELO-DE-DATOS.md       las tablas y por que estan asi');
    $this->line('    03-MULTITENANCY-Y-ROLES.md  el aislamiento entre operadores');
    $this->line('    06-INGRESO-VEHICULO.md      el recorrido del auto que entra');
    $this->line('    08-RESPALDOS.md             respaldar y restaurar');
    $this->line('    09-API.md                   los endpoints');
    $this->line('    10-PRUEBAS-Y-ESTADO.md      que esta probado y que no');
    $this->line('');
})->purpose('Dice donde esta la documentacion.');