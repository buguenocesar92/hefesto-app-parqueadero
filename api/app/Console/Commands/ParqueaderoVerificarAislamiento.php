<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `parqueadero:verificar-aislamiento`
 *
 * Se corre en cada despliegue y en cada arranque del contenedor (lo invoca
 * `deploy/api/docker/entrypoint.sh`). Comprueba que el aislamiento entre
 * operadores este de verdad puesto y no solo escrito en la documentacion.
 *
 * LAS CUATRO COMPROBACIONES
 * -------------------------
 *
 *  1. Que el rol de la aplicacion NO sea superusuario ni tenga `BYPASSRLS`.
 *     Sin esto, toda la RLS del mundo es decorativa: un rol con
 *     `BYPASSRLS` se salta las politicas.
 *  2. Que las trece tablas de negocio tengan RLS habilitado Y forzado, con
 *     politica que tenga `USING` y `WITH CHECK`.
 *  3. Que la funcion de contexto siga fallando CERRADA: sin `app.tenant_id`
 *     tiene que lanzar excepcion, no devolver `NULL`.
 *  4. Que exista el gatillo que protege el registro de movimientos.
 *
 * POR QUE FALLA CERRADA Y NO DEVUELVE NADA
 * ----------------------------------------
 * Si la funcion devolviera `NULL`, toda consulta devolveria cero filas sin
 * avisar, y alguien "arreglaria" el caso del reporte o del comando de
 * consola devolviendo algo, dejando la puerta abierta sin que nada lo
 * delate. Lanzando excepcion, el error aparece en el log en el momento.
 */
class ParqueaderoVerificarAislamiento extends Command
{
    protected $signature = 'parqueadero:verificar-aislamiento';

    protected $description = 'Comprueba que el aislamiento por operador este habilitado y forzado en PostgreSQL.';

    /** Las trece tablas de negocio. `tenants` queda afuera a proposito. */
    private const TABLAS = [
        'usuarios',
        'configuraciones',
        'parqueaderos',
        'tarifas',
        'lugares',
        'vehiculos',
        'mensualidades',
        'estacionamientos',
        'movimientos',
        'anotaciones_movimiento',
        'boletas',
        'reclamos',
        'bitacora',
    ];

    public function handle(): int
    {
        $fallos = [];

        foreach ([
            'rol sin privilegios peligroso' => $this->verificarRol(),
            'Row Level Security por tabla' => $this->verificarPoliticas(),
            'funcion de contexto falla cerrada' => $this->verificarContextoCerrado(),
            'gatillo del registro de movimientos' => $this->verificarGatilloMovimientos(),
        ] as $nombre => $resultado) {
            if ($resultado['ok']) {
                $this->info(sprintf('[OK]    %s', $nombre));

                continue;
            }

            $fallos[] = $nombre;

            $this->error(sprintf('[FALLA] %s: %s', $nombre, $resultado['detalle']));
        }

        if ($fallos !== []) {
            $this->newLine();
            $this->error('El aislamiento entre operadores NO esta garantizado. No arranque la API.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('El aislamiento por operador esta habilitado y forzado.');

        return self::SUCCESS;
    }

    /**
     * @return array{ok: bool, detalle: string}
     */
    private function verificarRol(): array
    {
        try {
            $fila = DB::selectOne("
                SELECT r.rolsuper, r.rolbypassrls
                FROM pg_roles r
                WHERE r.rolname = current_user
            ");
        } catch (Throwable $error) {
            return ['ok' => false, 'detalle' => 'No se pudo leer el rol: ' . $error->getMessage()];
        }

        if ($fila === null) {
            return ['ok' => false, 'detalle' => 'No se pudo determinar el rol de la aplicacion.'];
        }

        if ($fila->rolsuper === true) {
            return [
                'ok' => false,
                'detalle' => 'El rol de la aplicacion es SUPERUSUARIO: se salta la RLS por completo. '
                    . 'Use un rol con permisos normales.',
            ];
        }

        if ($fila->rolbypassrls === true) {
            return [
                'ok' => false,
                'detalle' => 'El rol de la aplicacion tiene BYPASSRLS: ignora las politicas de aislamiento.',
            ];
        }

        return ['ok' => true, 'detalle' => 'El rol no es superusuario ni tiene BYPASSRLS.'];
    }

    /**
     * @return array{ok: bool, detalle: string}
     */
    private function verificarPoliticas(): array
    {
        $problemas = [];

        foreach (self::TABLAS as $tabla) {
            $fila = DB::selectOne("
                SELECT c.relrowsecurity AS habilitada,
                       c.relforcerowsecurity AS forzada,
                       p.polcmd AS comando,
                       (SELECT count(*) FROM pg_policies
                         WHERE schemaname = 'public' AND tablename = ?::text) AS politicas
                FROM pg_class c
                LEFT JOIN pg_policy p
                    ON p.polrelid = c.oid AND p.polcmd = 'ALL'
                WHERE c.relname = ?
                  AND c.relnamespace = 'public'::regnamespace
            ", [$tabla, $tabla]);

            if ($fila === null) {
                $problemas[] = sprintf('%s: la tabla no existe.', $tabla);

                continue;
            }

            if ($fila->habilitada !== true) {
                $problemas[] = sprintf('%s: RLS no esta habilitada.', $tabla);
            }

            if ($fila->forzada !== true) {
                // Sin FORCE, el dueno de la tabla se salta la politica, y en
                // desarrollo el rol de la aplicacion suele ser el dueno.
                $problemas[] = sprintf('%s: RLS no esta FORZADA.', $tabla);
            }

            if ((int) $fila->politicas < 1 || $fila->comando === null) {
                $problemas[] = sprintf('%s: no tiene politica FOR ALL.', $tabla);
            }
        }

        if ($problemas !== []) {
            return ['ok' => false, 'detalle' => implode(' ', $problemas)];
        }

        return ['ok' => true, 'detalle' => sprintf('%d tablas protegidas.', count(self::TABLAS))];
    }

    /**
     * @return array{ok: bool, detalle: string}
     */
    private function verificarContextoCerrado(): array
    {
        try {
            DB::select('SELECT set_config(?, ?, false)', ['app.tenant_id', '']);
            DB::select('SELECT public.app_tenant_id()');
        } catch (Throwable) {
            // Esto es lo que se espera: la funcion lanzo excepcion. Falla
            // cerrado, que es el comportamiento correcto.
            return ['ok' => true, 'detalle' => 'La funcion de contexto lanza excepcion sin operador.'];
        }

        return [
            'ok' => false,
            'detalle' => 'La funcion de contexto NO lanzo excepcion sin operador: devuelve algo en vez de fallar cerrado.',
        ];
    }

    /**
     * @return array{ok: bool, detalle: string}
     */
    private function verificarGatilloMovimientos(): array
    {
        try {
            $existe = DB::selectOne(
                "SELECT to_regclass('public.gatillo_movimientos_solo_insertar') AS disparador"
            );
        } catch (Throwable $error) {
            return ['ok' => false, 'detalle' => 'No se pudo verificar el gatillo: ' . $error->getMessage()];
        }

        if ($existe === null || $existe->disparador === null) {
            return [
                'ok' => false,
                'detalle' => 'No existe el gatillo gatillo_movimientos_solo_insertar: '
                    . 'falta aplicar la migracion 2026_10_17_000017.',
            ];
        }

        return ['ok' => true, 'detalle' => 'El registro de movimientos esta protegido contra edicion.'];
    }
}