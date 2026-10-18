<template>
  <section>
    <h1>Salidas y cobro</h1>

    <p class="intro">
      Busca el vehículo por patente o por VIN y cobra. El tiempo que se cobra
      sale de la hora de entrada guardada en el sistema, nunca de lo que diga
      el cliente.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <form class="buscador" @submit.prevent="buscar">
      <div class="campo">
        <label class="campo__etiqueta" for="busqueda">Patente o VIN</label>
        <input
          id="busqueda"
          v-model="termino"
          type="text"
          autocomplete="off"
          placeholder="AB1234 o el VIN completo"
          required
        >
        <small class="ayuda">
          Si hay dos vehículos con la misma patente, la búsqueda devuelve ambos
          y eliges tú. El sistema no adivina.
        </small>
      </div>

      <button type="submit" class="boton" :disabled="buscando">
        {{ buscando ? 'Buscando…' : 'Buscar' }}
      </button>
    </form>

    <div v-if="sesion" class="aviso aviso--info">
      <p class="cobro">
        Vehículo <span class="cifra">{{ sesion.patente }}</span>, lugar
        <strong>{{ sesion.lugar_codigo }}</strong>, entró a las
        {{ hora(sesion.ingreso_en) }}.
      </p>
      <p class="cobro">
        Tiempo dentro: <strong class="cifra">{{ sesion.tiempo?.texto ?? '—' }}</strong>.
      </p>
      <p class="ayuda">
        El monto aparece al cobrar. Se calcula con la tarifa que estaba vigente
        el día del ingreso, no con la de hoy.
      </p>
    </div>

    <form v-if="sesion" class="formulario" @submit.prevent="cobrar">
      <div class="rejilla">
        <div class="campo">
          <label class="campo__etiqueta" for="pago">Forma de pago</label>
          <select id="pago" v-model="pago.forma_pago">
            <option value="efectivo">Efectivo</option>
            <option value="tarjeta">Tarjeta</option>
            <option value="transferencia">Transferencia</option>
          </select>
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="descuento">Descuento (pesos)</label>
          <input id="descuento" v-model.number="pago.descuento" type="number" min="0" step="100">
          <small class="ayuda">
            Un descuento no puede pasar del total. Si el cobro estaba mal, la
            boleta se anula con su motivo: no se edita.
          </small>
        </div>
      </div>

      <button type="submit" class="boton boton--principal" :disabled="cobrando">
        {{ cobrando ? 'Cobrando…' : 'Cobrar y emitir boleta' }}
      </button>
    </form>

    <div v-if="boleta" class="aviso aviso--ok">
      <p>
        Boleta <strong class="cifra">{{ boleta.folio }}</strong> por
        <strong class="cifra">$ {{ boleta.total.toLocaleString('es-CL') }}</strong>.
      </p>
      <p v-if="boleta.desglose?.explicacion" class="ayuda">
        {{ boleta.desglose.explicacion }}
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { Boleta, Sesion } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

interface SesionBuscada extends Sesion {
  tiempo: { horas: number; minutos: number; segundos: number; texto: string };
}

const termino = ref('');
const sesion = ref<SesionBuscada | null>(null);
const boleta = ref<Boleta | null>(null);
const error = ref<string | null>(null);
const buscando = ref(false);
const cobrando = ref(false);

const pago = reactive({ forma_pago: 'efectivo', descuento: 0 });

async function buscar() {
  error.value = null;
  boleta.value = null;
  sesion.value = null;
  buscando.value = true;

  const valor = termino.value.trim().toUpperCase();

  // El VIN tiene 17 caracteres. Si es mas largo, es una patente.
  const cuerpo = valor.length === 17
    ? { vin: valor, forma_pago: pago.forma_pago }
    : { patente: valor, forma_pago: pago.forma_pago };

  try {
    sesion.value = await $fetch<SesionBuscada>('/api/v1/salidas/buscar', {
      method: 'POST',
      body: cuerpo,
    });
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje
      ?? 'No hay ningún vehículo estacionado con esa identificación en este operador.';
  } finally {
    buscando.value = false;
  }
}

async function cobrar() {
  if (!sesion.value) {
    return;
  }

  error.value = null;
  cobrando.value = true;

  try {
    const respuesta = await $fetch<{ boleta: Boleta }>('/api/v1/salidas', {
      method: 'POST',
      body: {
        estacionamiento_id: sesion.value.id,
        forma_pago: pago.forma_pago,
        descuento: pago.descuento,
      },
    });

    boleta.value = respuesta.boleta;
    sesion.value = null;
    termino.value = '';
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo cobrar la salida.';
  } finally {
    cobrando.value = false;
  }
}

function hora(instante: string): string {
  return new Date(instante).toLocaleTimeString('es-CL', {
    hour: '2-digit',
    minute: '2-digit',
  });
}

useHead({ title: 'Salidas · Gestión de Estacionamiento' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 46rem;
}

.buscador {
  display: flex;
  align-items: flex-end;
  gap: 1rem;
  flex-wrap: wrap;
}

.buscador .campo {
  flex: 1 1 20rem;
}

.cobro {
  margin: 0.25rem 0;
}

.ayuda {
  font-size: 0.78rem;
  color: #627d98;
  margin: 0.25rem 0;
}

.formulario {
  margin-top: 1rem;
}

.rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0 1rem;
}
</style>