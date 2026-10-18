<template>
  <section>
    <h1>Boletas del día</h1>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <div v-if="total" class="cifras">
      <div class="cifras__item">
        <div class="cifras__valor cifra">{{ total.boletas }}</div>
        <div class="cifras__etiqueta">Boletas emitidas</div>
      </div>
      <div class="cifras__item">
        <div class="cifras__valor cifra">$ {{ total.total.toLocaleString('es-CL') }}</div>
        <div class="cifras__etiqueta">Total del día</div>
      </div>
    </div>

    <p v-if="cargando" class="aviso aviso--info">Cargando las boletas…</p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Folio</th>
          <th>Hora</th>
          <th>Patente</th>
          <th>Lugar</th>
          <th class="cifra">Total</th>
          <th>Pago</th>
          <th>Estado</th>
          <th v-if="esAdministrador">Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="fila in boletas" :key="fila.id">
          <td><span class="cifra">{{ fila.folio }}</span></td>
          <td>{{ hora(fila.emitida_en) }}</td>
          <td class="cifra">{{ fila.patente ?? '—' }}</td>
          <td>{{ fila.lugar_codigo ?? '—' }}</td>
          <td class="cifra">$ {{ fila.total.toLocaleString('es-CL') }}</td>
          <td>{{ fila.forma_pago }}</td>
          <td>
            <!-- La palabra va con el color: si no, el color solo no basta. -->
            <span v-if="fila.estado === 'emitida'" class="estado estado--ok">Emitida</span>
            <span v-else class="estado estado--neutro">Anulada</span>
            <small v-if="fila.anulacion_motivo" class="motivo">{{ fila.anulacion_motivo }}</small>
          </td>
          <td v-if="esAdministrador">
            <button
              v-if="fila.estado === 'emitida'"
              type="button"
              class="boton boton--peligro boton--discreto"
              @click="anular(fila)"
            >
              Anular
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { Boleta, TotalDia } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const { esAdministrador } = useSesion();
const ruta = useRoute();

const parqueadero = computed(() => (typeof ruta.query.parqueadero === 'string' ? ruta.query.parqueadero : ''));

const boletas = ref<Boleta[]>([]);
const total = ref<TotalDia | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar() {
  if (!parqueadero.value) {
    error.value = 'Falta indicar la sede. Vuelve al inicio y elige una.';
    cargando.value = false;
    return;
  }

  try {
    const respuesta = await $fetch<{ boletas: Boleta[]; total_del_dia: TotalDia }>(
      `/api/v1/sedes/${parqueadero.value}/boletas`,
    );

    boletas.value = respuesta.boletas;
    total.value = respuesta.total_del_dia;
  } catch {
    error.value = 'No se pudieron cargar las boletas del día.';
  } finally {
    cargando.value = false;
  }
}

/**
 * Anular una boleta.
 *
 * La API pide un motivo escrito y lo guarda en la propia boleta. Una boleta
 * anulada no se borra: queda con el total que se cobró y con la razón.
 */
async function anular(boleta: Boleta) {
  const motivo = window.prompt(
    `¿Por qué se anula la boleta ${boleta.folio}? Se guardará escrito en la boleta y no se puede borrar.`,
  );

  if (motivo === null || motivo.trim().length < 10) {
    return;
  }

  try {
    await $fetch(`/api/v1/boletas/${boleta.id}/anular`, {
      method: 'POST',
      body: { motivo },
    });

    await cargar();
  } catch {
    error.value = 'La boleta no se pudo anular. Revisa que tu rol tenga permiso.';
  }
}

function hora(instante: string): string {
  return new Date(instante).toLocaleTimeString('es-CL', {
    hour: '2-digit',
    minute: '2-digit',
  });
}

onMounted(cargar);

useHead({ title: 'Boletas · Gestión de Estacionamiento' });
</script>

<style scoped>
.motivo {
  display: block;
  font-size: 0.78rem;
  color: #627d98;
  max-width: 22rem;
}
</style>