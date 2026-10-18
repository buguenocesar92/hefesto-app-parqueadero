<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensualidades: el contrato entre el operador y quien paga un lugar todos
 * los meses.
 *
 * LAS TRES FECHAS NO SE MEZCLAN
 * -----------------------------
 * Esta tabla tiene tres fechas y separarlas es el punto de todo el modelo:
 *
 *  1. `fecha_inicio` y `fecha_termino`: el **contrato**. Cuando se termino
 *     el acuerdo comercial.
 *  2. `fecha_ultimo_pago`: el **pago**. Cuando se recibio la plata por
 *     ultima vez.
 *  3. `credencial_vence_en`: la **credencial**. Cuando expira el permiso
 *     para pasar.
 *
 * Si se mezclaran, un caso muy comun seria imposible de entender: el cliente
 * dejo de pagar y el contrato termino el mismo mes. Con una sola fecha no se
 * sabe cual de las dos cosas paso, y el operario termina cobrando a quien ya
 * no tiene contrato, o dando acceso a quien no pago.
 *
 * La regla de negocio (si la mensualidad vencida bloquea el acceso) NO esta
 * en esta tabla: esta en `configuraciones.mensualidad_vencida_bloquea_acceso`,
 * porque es una decision del operador y no una verdad universal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensualidades', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('parqueadero_id')
                ->constrained('parqueaderos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $tabla->foreignUuid('vehiculo_id')
                ->constrained('vehiculos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Identidad del cliente. Es dato personal: se guarda lo necesario
            // para cobrar y para poder contactar, nada mas. Ver docs/07.
            $tabla->string('cliente_nombre', 160);
            $tabla->string('cliente_rut', 20)->nullable();
            $tabla->string('cliente_correo', 200)->nullable();
            $tabla->string('cliente_telefono', 40)->nullable();

            // 1. Contrato.
            $tabla->date('fecha_inicio');
            $tabla->date('fecha_termino')->nullable();

            // 2. Pago.
            $tabla->date('fecha_ultimo_pago')->nullable();

            // 3. Credencial: tiene su propia fecha de termino, distinta de
            // la del contrato. Si la credencial vence antes que el contrato,
            // el mensualista quedo sin permiso para pasar aunque siga
            // pagando, y eso es un problema distinto al de "no pago".
            $tabla->date('credencial_vence_en');

            $tabla->string('credencial_codigo', 32)->nullable();

            $tabla->unsignedInteger('monto_mensual');

            // Uno de: activa, suspendida, terminada.
            $tabla->string('estado', 24)->default('activa');

            $tabla->timestampsTz();

            // El codigo de credencial identifica al mensualista en el porton.
            // Se unico por operador, no global, para que dos operadores
            // puedan usar el mismo codigo sin tocarse.
            $tabla->unique(
                ['tenant_id', 'credencial_codigo'],
                'unico_codigo_credencial_por_operador'
            );

            // Consulta frecuente: quien esta vencido y a quien hay que
            // llamar. El indice ordena por credencial, que es la fecha que
            // decide el acceso.
            $tabla->index(
                ['tenant_id', 'estado', 'credencial_vence_en'],
                'indice_mensualidades_por_vencimiento'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensualidades');
    }
};
