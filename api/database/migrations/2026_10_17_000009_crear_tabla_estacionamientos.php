<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estacionamientos: una sesion de un vehiculo dentro de un lugar.
 *
 * Una fila nace al ingreso y se cierra al registrar la salida. Es la sesion,
 * no el evento: el evento esta en `movimientos`.
 *
 * POR QUE HAY DOS TABLAS Y NO UNA
 * -------------------------------
 * `estacionamientos` responde "cuanto se cobra y desde cuando esta este
 * auto en A-12". `movimientos` responde "que paso, cuando y quien lo hizo".
 *
 * Se separan porque responden preguntas distintas y tienen etapas distintas
 * de vida:
 *
 *  - La sesion se **actualiza** (se le pone la salida y el monto).
 *  - El movimiento es **inmutable**: no se corrige, se agrega una
 *    anotacion. Es el respaldo ante un reclamo.
 *
 * Mezclarlas obligaria a actualizar la foto de ingreso al cobrar, y con eso
 * se pierde la evidencia original.
 *
 * `salida_en IS NULL` significa "sesion abierta". Ese estado lo consulta el
 * indice unico parcial de la migracion 2026_10_17_000015, que es la garantia
 * de concurrencia del sistema. Ver docs/02, seccion "El conteo de lugares".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estacionamientos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('parqueadero_id')
                ->constrained('parqueaderos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // El vinculo con el vehiculo va por `vehiculo_id`, no por patente:
            // la patente identifica, pero dos vehiculos de una misma familia
            // la comparten.
            $tabla->foreignUuid('vehiculo_id')
                ->constrained('vehiculos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $tabla->foreignUuid('lugar_id')
                ->constrained('lugares')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $tabla->string('patente', 12);
            $tabla->string('vin', 17);

            $tabla->timestampTz('ingreso_en');

            // NULL mientras la sesion esta abierta. Todos los indices de
            // ocupacion filtran por `salida_en IS NULL`.
            $tabla->timestampTz('salida_en')->nullable();

            // Tiempo real permanecido, en SEGUNDOS.
            //
            // Se calcula al cerrar la sesion y se guarda. Guardar el
            // resultado es lo que permite reconstruir la cuenta desde el
            // registro aunque despues cambie la tarifa.
            $tabla->unsignedBigInteger('segundos_permanencia')->nullable();

            $tabla->foreignUuid('boleta_id')->nullable();

            $tabla->timestampsTz();

            // Consulta de ocupacion: por sede, solo sesiones abiertas. Este
            // indice es el que hace barato el conteo de lugares.
            $tabla->index(
                ['parqueadero_id', 'salida_en'],
                'indice_estacionamientos_por_sede'
            );

            // Cierre de sesion desde la pantalla de salida: buscar la sesion
            // abierta de un vehiculo.
            $tabla->index(
                ['tenant_id', 'vin', 'salida_en'],
                'indice_estacionamientos_por_vin'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estacionamientos');
    }
};
