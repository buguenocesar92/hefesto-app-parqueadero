<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lugares (plazas) de estacionamiento.
 *
 * Un lugar tiene un codigo legible por el operario ("A-12"), que es lo que
 * el cliente escucha por radio. El codigo es unico **dentro de la sede**,
 * no del operador: dos sedes distintas pueden tener el mismo codigo.
 *
 * `tipo` distingue el uso esperado del lugar:
 *  - visitante: se cobra por tiempo.
 *  - mensualista: reservado por contrato.
 *  - reservado: para un cliente o una empresa concreta.
 *  - carga: para operaciones de carga y descarga.
 *
 * Un lugar no tiene columna `ocupado`. La ocupacion se deriva de
 * `estacionamientos`, y la garantia de que un lugar tenga a lo sumo una
 * sesion abierta la impose un indice unico parcial (migracion
 * 2026_10_17_000015). Ver docs/02, seccion "El conteo de lugares".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lugares', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('parqueadero_id')
                ->constrained('parqueaderos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $tabla->string('codigo', 24);

            // Uno de: visitante, mensualista, reservado, carga.
            $tabla->string('tipo', 24)->default('visitante');

            // Si el lugar es reservado, para quien. Texto libre: puede ser
            // una empresa, un contrato o un meson.
            $tabla->string('reservado_para', 160)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(
                ['parqueadero_id', 'codigo'],
                'unico_lugar_por_codigo_en_sede'
            );

            $tabla->index(['tenant_id', 'parqueadero_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lugares');
    }
};
