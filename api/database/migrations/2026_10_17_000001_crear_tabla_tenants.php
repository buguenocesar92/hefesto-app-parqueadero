<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operadores de parqueadero.
 *
 * Un `tenant` es un operador: la empresa o el profesional que administra uno
 * o mas estacionamientos. No es un sede. Las sedes son `parqueaderos`.
 *
 * Esta tabla NO lleva Row Level Security, a proposito: se consulta durante
 * el ingreso a la aplicacion, cuando todavia no hay sesion y por lo tanto no
 * hay `app.tenant_id` que poner. La compensacion es la vista
 * `vw_tenants_publicos` (migracion 2026_10_17_000016) y que el rol de la
 * aplicacion no sea superusuario ni tenga BYPASSRLS. Ver docs/03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            // Identificador corto y legible, usado en enlaces de acceso.
            $tabla->string('slug', 64)->unique();

            $tabla->string('nombre', 160);

            // Razon social y contacto: datos personales de acuerdo a la
            // Ley 19.628. Se guardan porque son los datos de facturacion.
            $tabla->string('razon_social', 200)->nullable();
            $tabla->string('rut', 20)->nullable();
            $tabla->string('correo_contacto', 200)->nullable();
            $tabla->string('telefono_contacto', 40)->nullable();

            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            $tabla->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
