<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reclamos de los clientes.
 *
 * UNA FOTO ES OBLIGATORIA
 * -----------------------
 * Un reclamo sin foto se resuelve mal, porque no hay contra que contrastar.
 * El cliente dice "mi auto no estaba el jueves" y el operador tiene que
 * responder sin nada. Si hay foto de ingreso con fecha y hora, la discusion
 * termina en el registro.
 *
 * Por eso `foto_ruta` NO acepta nulos a nivel de base de datos, no solo en
 * el formulario. Si el formulario se relaja alguna vez, la base sigue
 * impidiendo dejar un reclamo sin respaldo.
 *
 * `fecha_incidente` es obligatoria y separada de `creado_en`. Son cosas
 * distintas: el reclamo se escribe el viernes sobre lo que paso el jueves.
 * Con una sola fecha no se puede buscar "todos los reclamos del jueves".
 *
 * `estacionamiento_id` es opcional a proposito: hay reclamos de hechos que
 * no producen una sesion (un auto estacionado en un lugar ajeno, una puerta
 * golpeada en un dia sin registro de vehiculo). Para esos casos esta
 * `patente_vista` y `fecha_incidente`, y la busqueda del movimiento
 * correspondiente se hace por fecha. Ver App\Services\Reclamos\BuscadorPorFecha.
 *
 * DATOS PERSONALES
 * ----------------
 * Un reclamo suele traer nombre, RUT, telefono y la foto, que puede mostrar
 * a una persona. Todo eso es dato personal bajo la Ley 19.628. Se guarda lo
 * necesario para resolver el reclamo y nada mas. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclamos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('parqueadero_id')
                ->constrained('parqueaderos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Opcional: no todos los reclamos tienen una sesion asociada.
            $tabla->foreignUuid('estacionamiento_id')
                ->nullable()
                ->constrained('estacionamientos')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $tabla->uuid('registrado_por');

            // Folio corto que el cliente puede dictar por telefono.
            $tabla->string('folio', 24);

            // Uno de: abierto, en_revision, resuelto, rechazado.
            $tabla->string('estado', 24)->default('abierto');

            // Cuando paso el hecho. Distinto de cuando se reporto.
            $tabla->date('fecha_incidente');
            $tabla->timestampTz('hora_incidente')->nullable();

            $tabla->string('patente_vista', 12)->nullable();

            $tabla->string('cliente_nombre', 160);
            $tabla->string('cliente_rut', 20)->nullable();
            $tabla->string('cliente_contacto', 120)->nullable();

            $tabla->text('descripcion');

            // Obligatoria a nivel de esquema, no solo de formulario.
            $tabla->string('foto_ruta', 300);
            $tabla->timestampTz('foto_tomada_en')->nullable();

            $tabla->text('resolucion')->nullable();
            $tabla->uuid('resuelto_por')->nullable();
            $tabla->timestampTz('resuelto_en')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(
                ['tenant_id', 'folio'],
                'unico_folio_reclamo_por_operador'
            );

            // La pantalla de reclamos ordena por fecha de incidente y filtra
            // por estado: este indice cubre las dos juntas.
            $tabla->index(
                ['tenant_id', 'estado', 'fecha_incidente'],
                'indice_reclamos_por_estado_y_fecha'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclamos');
    }
};
