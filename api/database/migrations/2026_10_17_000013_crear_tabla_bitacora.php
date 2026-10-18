<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de auditoria.
 *
 * El tercer motivo por el que el operador pierde plata es el que anota a
 * mano: sin registro, no hay forma de demostrar nada. Esta tabla es el
 * registro de "quien hizo que".
 *
 * Se escribe dentro de la misma transaccion que el cambio. Es deliberado: si
 * la bitacora se escribiera despues, un fallo entre medio dejaria el cambio
 * hecho sin rastro, que es justo el caso que el operador necesita poder
 * demostrar.
 *
 * `datos` guarda el contexto en JSON. No se guardan contrasenas, tokens ni
 * el contenido de las fotos: solo identificadores y el resultado. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $tabla): void {
            $tabla->bigIncrements('id');

            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Puede ser nulo en acciones automaticas.
            $tabla->uuid('usuario_id')->nullable();

            // Uno de: ingreso, salida, boleta, mensualidad, reclamo,
            // administracion, seguridad.
            $tabla->string('ambito', 32);

            // Uno de: crear, leer, actualizar, borrar, anular.
            $tabla->string('accion', 24);

            $tabla->string('recurso_tipo', 48);
            $tabla->uuid('recurso_id')->nullable();

            $tabla->string('descripcion', 300);

            $tabla->jsonb('datos')->nullable();

            $tabla->string('direccion_ip', 45)->nullable();

            $tabla->timestampTz('creado_en');

            $tabla->index(['tenant_id', 'creado_en'], 'indice_bitacora_por_fecha');
            $tabla->index(
                ['tenant_id', 'recurso_tipo', 'recurso_id'],
                'indice_bitacora_por_recurso'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
    }
};
