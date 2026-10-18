<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehiculos conocidos por el operador.
 *
 * LA REGLA QUE IMPORTA
 * --------------------
 * Una patente puede tener mas de un vehiculo estacionado a la vez: dos
 * autos de la misma familia, o el auto y la camioneta de un mismo dueño. Lo
 * que los distingue es el VIN, no la patente.
 *
 * Por eso el indice unico es sobre `vin` y **no** sobre `patente`. Si se
 * hiciera unico por patente, la segunda familia que llegue con el mismo
 * numero quedaria rechazada, que es exactamente el error que el operador
 * quiere evitar.
 *
 * Consecuencia de diseño: `patente` es un atributo del vehiculo, no su
 * identidad. Los movimientos guardan la patente como texto ademas del
 * `vehiculo_id`, porque en un reclamo hay que poder leer la patente tal como
 * se escribio ese dia, aunque despues se haya corregido el registro.
 *
 * Datos personales: la patente es un dato que identifica a una persona o a
 * su vehiculo. Se guarda lo necesario para operar y nada mas. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $tabla->string('patente', 12);

            // VIN: identificador unico del vehiculo. 17 caracteres segun la
            // norma ISO 3779. Se guarda en mayusculas, sin guiones ni
            // espacios, para que la busqueda no falle por formato.
            $tabla->string('vin', 17);

            // Uno de: automobile, camioneta, moto, camion, camioneta_furgon.
            $tabla->string('tipo_vehiculo', 24);

            $tabla->string('color', 40)->nullable();
            $tabla->string('marca', 60)->nullable();
            $tabla->string('modelo', 60)->nullable();

            $tabla->timestampsTz();

            // El VIN distingue al vehiculo dentro del operador.
            $tabla->unique(
                ['tenant_id', 'vin'],
                'unico_vin_por_operador'
            );

            // La patente NO es unica a proposito. Este indice existe para
            // poder buscar "todos los vehiculos con esta patente", que es
            // lo que necesita el operario cuando una familia trae dos autos.
            $tabla->index(
                ['tenant_id', 'patente'],
                'indice_vehiculos_por_patente'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
    }
};
