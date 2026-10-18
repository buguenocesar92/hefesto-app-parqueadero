<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boletas: el cobro de un estacionamiento.
 *
 * EL CALCULO ES DEL SERVIDOR
 * --------------------------
 * La API nunca recibe un monto del cliente. Recibe el `estacionamiento_id`
 * y calcula el tiempo, busca la tarifa vigente y devuelve el resultado. Un
 * navegador que mande `total: 0` no cambia nada: el campo no se lee de la
 * peticion. Ver App\Services\Boletas\GeneradorDeBoleta.
 *
 * `desglose` guarda el detalle del calculo (tramos, minutos cobrados, valor
 * de cada tramo) en JSON. Sin eso, una boleta de tres meses no se puede
 * explicar cuando la tarifa haya cambiado. Y el ticket que se entrega al
 * cliente es un respaldo: si el cliente lo pierde, la cuenta se reconstruye
 * desde aqui, porque el tiempo real quedo guardado en
 * `estacionamientos.segundos_permanencia`.
 *
 * `folio` es unico por operador: dos sedes pueden emitir el mismo numero sin
 * tocarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boletas', function (Blueprint $tabla): void {
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
                ->unique()
                ->constrained('estacionamientos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->uuid('operador_id');

            $tabla->string('folio', 32);

            $tabla->timestampTz('emitida_en');

            // Detalle del calculo, tal como lo hizo el servidor.
            $tabla->jsonb('desglose');

            // Montos en pesos enteros. Nunca float.
            $tabla->unsignedInteger('subtotal');
            $tabla->unsignedInteger('total');
            $tabla->unsignedInteger('descuento')->default(0);

            $tabla->string('forma_pago', 24);

            // Uno de: emitida, anulada.
            $tabla->string('estado', 16)->default('emitida');

            $tabla->string('anulacion_motivo', 300)->nullable();

            $tabla->timestampsTz();

            $tabla->unique(
                ['tenant_id', 'folio'],
                'unico_folio_por_operador'
            );

            // Cierre del dia sin que nadie tenga que hacer cuentas.
            $tabla->index(
                ['tenant_id', 'parqueadero_id', 'emitida_en'],
                'indice_boletas_por_sede_y_fecha'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boletas');
    }
};
