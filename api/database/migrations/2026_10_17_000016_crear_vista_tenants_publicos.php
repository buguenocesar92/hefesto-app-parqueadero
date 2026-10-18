<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista publica de operadores: lo unico que se puede leer antes del ingreso.
 *
 * `tenants` NO tiene Row Level Security, porque se consulta durante el
 * ingreso a la aplicacion, cuando todavia no hay sesion y por lo tanto no
 * hay `app.tenant_id` que poner. Esa es una excepcion consciente.
 *
 * La compensacion son dos:
 *
 *  1. Esta vista. Expone **tres** columnas y solo de los operadores
 *     activos. No expone razon social, RUT, correo ni telefono.
 *  2. Que el rol de la aplicacion no sea superusuario ni tenga BYPASSRLS.
 *     Quien tenga acceso SQL directo a la base puede leer `tenants`
 *     entera. Por eso el despliegue exige revisar los permisos y
 *     `php artisan parqueadero:verificar-aislamiento` lo comprueba.
 *
 * RIESGO RESIDUAL, DICHO CON TODAS LAS LETRAS
 * ------------------------------------------
 * Un `slug` se puede enumerar antes de autenticarse, porque aparece en la
 * URL de entrada. Un atacante puede descubrir que el Estacionamiento Apolo
 * usa la aplicacion. No puede ver mas que el nombre. Si eso se considera
 * inaceptable, la alternativa es dejar el campo en blanco hasta el ingreso
 * y resolver siempre por dominio de correo; queda anotado como decision
 * pendiente del cliente, no como algo resuelto. Ver docs/03.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_tenants_publicos AS
                SELECT
                    tenants.slug,
                    tenants.nombre,
                    tenants.activo
                FROM public.tenants;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON VIEW public.vw_tenants_publicos IS
                'Unica lectura de operadores permitida antes del ingreso. No incluye razon social, RUT ni contactos.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_tenants_publicos');
    }
};
