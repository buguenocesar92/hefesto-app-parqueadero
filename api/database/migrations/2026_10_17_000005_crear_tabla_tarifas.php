<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifas de cobro.
 *
 * Hay dos formas de cobrar, y el operador puede tener las dos:
 *
 *  - `por_tiempo`: se cobra segun el tiempo permanecido. El calculo lo
 *    hace el servidor, por segundos. Ver App\Services\Estacionamiento\
 *    CalculadoraTarifa.
 *  - `por_evento`: tarifa fija por tipo de vehiculo, sin importar el
 *    tiempo (por ejemplo, tarifas de dia completo para Loeb o para visitas).
 *
 * `minutos_incluidos` es la cortesia o el tramo ya pagado: el tiempo que se
 * descuenta antes de empezar a cobrar el resto.
 *
 * `vigente_desde` y `vigente_hasta` permiten guardar el historial de
 * tarifas. Al revisar una boleta de hace seis meses hay que poder preguntar
 * "cuanto valia la tarifa ese dia", no "cuanto vale hoy": sin vigencia, la
 * boleta vieja no se puede explicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('parqueadero_id')
                ->constrained('parqueaderos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $tabla->string('nombre', 120);

            // Uno de: por_tiempo, por_evento.
            $tabla->string('tipo', 24);

            // Valor en pesos enteros. No se usa float: el redondeo de un
            // float en un cobro produce diferencias de un peso entre dos
            // boletas del mismo tiempo.
            $tabla->unsignedInteger('valor');

            // Para `por_tiempo`, el valor es por hora completa o por fraccion.
            $tabla->unsignedSmallInteger('minutos_por_fraccion')->nullable();

            // Minutos que se descuentan antes de cobrar (cortesia o tramo
            // pagado por adelantado).
            $tabla->unsignedSmallInteger('minutos_incluidos')->default(0);

            // Tipo de vehiculo al que aplica. Si es null, aplica a todos.
            $tabla->string('tipo_vehiculo', 24)->nullable();

            $tabla->timestampTz('vigente_desde');
            $tabla->timestampTz('vigente_hasta')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            // La busqueda de la tarifa vigente se hace por sede, tipo,
            // vigencia y estado: este indice cubre ese caso.
            $tabla->index(
                ['tenant_id', 'parqueadero_id', 'tipo', 'activo', 'vigente_desde'],
                'indice_tarifas_vigentes'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas');
    }
};
