<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sedes: los estacionamientos fisicos de un operador.
 *
 * Un operador puede tener mas de uno (un edificio, un centro comercial, dos
 * patios). Todos los movimientos, lugares y tarifas cuelgan de una sede.
 *
 * `capacidad_total` es un dato declarado por el operador, no un dato derivado.
 * La diferencia entre lo declarado y lo contado es informacion util: si se
 *occupied mas de lo declarado, o el conteo de lugares esta mal, o hay
 * sobrecupo real. Por eso se guardan los dos y no uno solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parqueaderos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $tabla->string('nombre', 160);
            $tabla->string('direccion', 300)->nullable();

            // Capacidad declarada. Si la ocupacion la supera, la pantalla lo
            // muestra como dato, no como error. Ver docs/02, seccion
            // "Sobreocupacion".
            $tabla->unsignedInteger('capacidad_total')->default(0);

            $tabla->string('zona_horaria', 64)->default('America/Santiago');

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parqueaderos');
    }
};
