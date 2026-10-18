<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos: el registro de cada entrada y de cada salida.
 *
 * Esta tabla es el corazon del producto. Cada vez que un vehiculo entra
 * queda una fila; cada vez que sale, otra. Con eso la anotacion manual se
 * convierte en evidencia.
 *
 * ES SOLO LECTURA, EN LA PRACTICA
 * -------------------------------
 * No hay ninguna ruta de la aplicacion que actualice o borre un movimiento.
 * Un movimiento no se corrige: se agrega una anotacion en
 * `anotaciones_movimiento` y el original queda intacto. Esa es la unica
 * forma de que "el vehiculo salio a las 14:05" siga siendo exactamente eso
 * seis meses despues, aunque despues se haya detectado que fue a las 14:50.
 *
 * POR QUE SE DUPLICA LA PATENTE Y EL VIN
 * ---------------------------------------
 * Los movimientos guardan `patente` y `vin` como texto, ademas del
 * `vehiculo_id`. Es una copia deliberada:
 *
 *  - El reclamo se lee en el registro, no contra el vehiculo.
 *  - Si alguien corrige la patente de un vehiculo, los movimientos antigos
 *    deben seguir mostrando lo que se escribio ese dia.
 *
 * La incoherencia es imposible por construccion: `vehiculo_id` no puede
 * quedar apuntando a otro vehiculo que el que se registro, porque el VIN
 * del movimiento se copia del mismo registro en la misma transaccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('parqueadero_id')
                ->constrained('parqueaderos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('estacionamiento_id')
                ->constrained('estacionamientos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $tabla->uuid('operador_id');

            // Uno de: ingreso, salida, anulacion.
            $tabla->string('tipo', 16);

            $tabla->timestampTz('ocurrido_en');

            // Copia literal del momento. Ver la nota del encabezado.
            $tabla->string('patente', 12);
            $tabla->string('vin', 17);
            $tabla->string('tipo_vehiculo', 24);
            $tabla->string('lugar_codigo', 24);

            // Ruta del archivo de la foto, relativa al volumen de fotos.
            // La foto es lo que convierte el registro en evidencia ante un
            // reclamo, por eso se guarda la referencia a la foto de cada
            // movimiento, no solo la de ingreso.
            $tabla->string('foto_ruta', 300)->nullable();
            $tabla->timestampTz('foto_tomada_en')->nullable();

            $tabla->timestampsTz();

            // Búsqueda por fecha. Un reclamo del auto que estuvo ayer exige
            // buscar por ventana de fechas, no solo el ultimo ingreso.
            $tabla->index(
                ['tenant_id', 'ocurrido_en'],
                'indice_movimientos_por_fecha'
            );

            $tabla->index(
                ['tenant_id', 'patente', 'ocurrido_en'],
                'indice_movimientos_por_patente_y_fecha'
            );

            $tabla->index(
                ['tenant_id', 'vin', 'ocurrido_en'],
                'indice_movimientos_por_vin_y_fecha'
            );
        });

        Schema::create('anotaciones_movimiento', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('movimiento_id')
                ->constrained('movimientos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->uuid('usuario_id');

            $tabla->text('texto');

            $tabla->timestampTz('creado_en');

            $tabla->index(['tenant_id', 'movimiento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anotaciones_movimiento');
        Schema::dropIfExists('movimientos');
    }
};
