<template>
  <section>
    <h1>Mensualidades</h1>

    <p class="intro">
      Tres fechas y tres problemas distintos: el contrato, el pago y la
      credencial. Por eso esta pantalla las muestra separadas.
    </p>

    <ul class="reglas">
      <li>
        <strong>Contrato terminado</strong>: se acabó el acuerdo. No hay nada
        que cobrar y hay que devolver el lugar.
      </li>
      <li>
        <strong>Pago atrasado</strong>: el contrato sigue vigente pero no se
        ha pagado. Acá sí hay plata pendiente.
      </li>
      <li>
        <strong>Credencial vencida</strong>: el cliente pagó y quedó sin
        permiso para pasar. Se arregla renovando la credencial, no cobrando.
      </li>
    </ul>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <h2>Por cobrar</h2>

    <p v-if="cargando" class="aviso aviso--info">Cargando…</p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Patente</th>
          <th>Cliente</th>
          <th class="cifra">Monto</th>
          <th>Vence la credencial</th>
          <th>Situación</th>
          <th v-if="puedeCobrar">Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="mensualidad in porCobrar" :key="String(mensualidad.id)">
          <td class="cifra">{{ mensualidad.patente ?? '—' }}</td>
          <td>{{ mensualidad.cliente_nombre }}</td>
          <td class="cifra">$ {{ Number(mensualidad.monto_mensual).toLocaleString('es-CL') }}</td>
          <td>{{ mensualidad.credencial_vence_en }}</td>
          <td>
            <!-- Días negativos significa vencida. La palabra va con el color. -->
            <span
              v-if="Number(mensualidad.dias_para_vencer) < 0"
              class="estado estado--peligro"
            >
              Vencida hace {{ Math.abs(Number(mensualidad.dias_para_vencer)) }} día(s)
            </span>
            <span v-else-if="Number(mensualidad.dias_para_vencer) <= 7" class="estado estado--aviso">
              Vence en {{ mensualidad.dias_para_vencer }} día(s)
            </span>
            <span v-else class="estado estado--ok">Al día</span>
          </td>
          <td v-if="puedeCobrar">
            <button type="button" class="boton boton--discreto" @click="pagar(mensualidad)">
              Registrar pago
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="pie">
      Una mensualidad no termina sola porque pasó la fecha. El paso del tiempo
      es un aviso, no una acción: la ejecuta una persona. Un sistema que
      termina contratos por su cuenta se equivoca de día y se lleva a un
      cliente que sí pagó.
    </p>
  </section>
</template>

<script setup lang="ts">
import type { Mensualidad } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const { puedeCobrar } = useSesion();

/**
 * La respuesta de `/mensualidades/por-cobrar` es una lista de resumenes con
 * la forma de `Mensualidad` mas los días para vencer. Se tipa aparte porque
 * la API devuelve esos campos en un arreglo de objetos.
 */
interface Cobrable extends Mensualidad {
  dias_para_vencer: number;
}

const porCobrar = ref<Cobrable[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar() {
  try {
    const respuesta = await $fetch<{ mensualidades: Cobrable[] }>(
      '/api/v1/mensualidades/por-cobrar',
    );

    porCobrar.value = respuesta.mensualidades;
  } catch {
    error.value = 'No se pudo cargar la lista de mensualidades por cobrar.';
  } finally {
    cargando.value = false;
  }
}

async function pagar(mensualidad: Cobrable) {
  try {
    await $fetch(`/api/v1/mensualidades/${mensualidad.id}/pagos`, {
      method: 'POST',
      body: { meses: 1 },
    });

    await cargar();
  } catch {
    error.value = 'El pago no se pudo registrar.';
  }
}

onMounted(cargar);

useHead({ title: 'Mensualidades · Gestión de Estacionamiento' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 46rem;
}

.reglas {
  background: #ffffff;
  border: 1px solid #d9e2ec;
  border-radius: 0.375rem;
  padding: 1rem 1.25rem 1rem 2.4rem;
  max-width: 46rem;
  color: #52606d;
}

.pie {
  margin-top: 1.5rem;
  font-size: 0.85rem;
  color: #52606d;
  max-width: 46rem;
}
</style>