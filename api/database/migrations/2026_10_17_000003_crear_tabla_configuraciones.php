<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametros operativos que cambian de un operador a otro.
 *
 * El caso importante es `mensualidad_vencida_bloquea_acceso`. Que una
 * mensualidad vencida impida o no el ingreso **no es una verdad universal**:
 * hay operadores que dejan pasar al mensualista mientras conversan con el
 * cliente, y hay operadores que la tratan como un bloqueo real. Por eso es
 * un parametro del operador y no una constante del codigo.
 *
 * Guardarlo aca y no en Authentik o en variables de entorno es a proposito:
 * lo cambia el administrador del operador desde la pantalla de
 * administracion, sin que alguien tenga que editar el servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->unique()
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Si es `true`, el ingreso de un vehiculo con mensualidad
            // vencida queda marcado y el operario ve el aviso. La decision
            // final la toma una persona: la aplicacion registra, no bloquea
            // el porton. Ver README (advertencia) y docs/06.
            $tabla->boolean('mensualidad_vencida_bloquea_acceso')->default(true);

            // Minutos de cortesia que se restan antes de empezar a cobrar.
            // Afecta el cobro, no el tiempo registrado: el tiempo real
            // siempre queda guardado en segundos.
            $tabla->unsignedSmallInteger('minutos_cortesia')->default(10);

            // Minutos redondeados hacia arriba al aplicar la tarifa por
            // tiempo. El calculo crudo sigue siendo por segundos.
            $tabla->unsignedSmallInteger('minutos_redondeo')->default(1);

            $tabla->string('zona_horaria', 64)->default('America/Santiago');

            // Dias de margen para avisar antes de que venza una mensualidad.
            $tabla->unsignedSmallInteger('dias_aviso_vencimiento')->default(15);

            $tabla->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
