<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operarios y administradores del operador.
 *
 * La identidad viene de Authentik. Aqui solo se guarda el vinculo con la
 * identidad externa (`authentik_subject`) y el rol que el operador le
 * asigno dentro de su propia empresa.
 *
 * No se guarda contrasena: la aplicacion nunca la ve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Identificador del sujeto en Authentik (claim `sub` del id_token).
            $tabla->string('authentik_subject', 255)->unique();

            $tabla->string('nombre', 160);
            $tabla->string('correo', 200);

            // Uno de: administrador, supervisor, operario, lectura.
            $tabla->string('rol', 32);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_ingreso')->nullable();

            $tabla->timestampsTz();

            // El indice mas usado: listar operarios de un operador.
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
