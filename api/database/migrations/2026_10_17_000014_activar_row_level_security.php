<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AISLAMIENTO ENTRE OPERADORES: Row Level Security (RLS) de PostgreSQL.
 *
 * Se aplica exactamente el patron ya decidido y documentado en
 * `apps/portal-autoservicio/docs/03-MULTITENANCY.md`: columna `tenant_id`
 * mas RLS, con `WITH CHECK`, `FORCE ROW LEVEL SECURITY` y el contexto
 * viajando con `set_config(..., true)` (alcance de transaccion). Aqui no se
 * invento un cuarto enfoque.
 *
 * MECANISMO
 * ---------
 * 1. `public.app_tenant_id()` lee la variable de sesion `app.tenant_id`. Si
 *    no esta definida, LANZA un error. No devuelve NULL: falla cerrado.
 * 2. Cada tabla de negocio recibe una politica que exige
 *    `tenant_id = app_tenant_id()`, al leer (USING) y al escribir
 *    (WITH CHECK).
 * 3. `FORCE ROW LEVEL SECURITY` hace que la politica aplique tambien al
 *    dueno de la tabla.
 * 4. El contexto se fija con `set_config(..., true)`: al confirmar o
 *    revertir, PostgreSQL lo descarta. Por eso un worker de colas que
 *    reutiliza la conexion no puede arrastrar el tenant de otro operador.
 *
 * `tenants` NO lleva RLS a proposito: se consulta antes del ingreso a la
 * aplicacion. Se compensa con la vista `vw_tenants_publicos`
 * (migracion 2026_10_17_000016).
 *
 * REQUISITO DE DESPLIEGUE: el rol de la aplicacion NO puede ser
 * superusuario ni tener BYPASSRLS. Con cualquiera de los dos, PostgreSQL
 * ignora las politicas por completo. Lo comprueba
 * `php artisan parqueadero:verificar-aislamiento`.
 */
return new class extends Migration
{
    /**
     * Tablas con datos de negocio, y por lo tanto con RLS.
     *
     * @var list<string>
     */
    private array $tablasProtegidas = [
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

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'No hay contexto de operador en la conexion (app.tenant_id no esta definida). Se aborta la operacion para no exponer datos de otro operador.',
                        HINT = 'La peticion no paso por App\Http\Middleware\ResolverTenant, o la operacion se ejecuto fuera de una transaccion.';
                END IF;

                RETURN valor::uuid;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
                'Devuelve el operador de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);

        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT to_regclass(?) AS tabla',
                ['public.' . $tabla]
            );

            // to_regclass devuelve NULL si la tabla todavia no existe.
            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

            $nombre = 'politica_aislamiento_' . $tabla;

            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$nombre} ON public.{$tabla};

                CREATE POLICY {$nombre} ON public.{$tabla}
                    FOR ALL
                    TO PUBLIC
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL);
        }

        $this->verificarColumnaTenant();
    }

    public function down(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('DROP POLICY IF EXISTS politica_aislamiento_' . $tabla . ' ON public.' . $tabla);
            DB::unprepared('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene `tenant_id`.
     * Sin esto, un error de tipeo dejaria una tabla de datos de negocio sin
     * politica y la migracion pasaria igual.
     */
    private function verificarColumnaTenant(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ''public'' AND table_name = ? AND column_name = ''tenant_id''
                 ) AS tiene_columna',
                [$tabla]
            );

            if ($existe === null || ! $existe->tiene_columna) {
                throw new RuntimeException(
                    'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                    . 'Revise la migracion antes de continuar.'
                );
            }
        }
    }
};
