<template>
  <section>
    <h1>Reclamos</h1>

    <p class="intro">
      Un reclamo se responde con lo que dice el registro, no con lo que se
      recuerda. Antes de abrir la ficha, busca en el movimiento: qué vehículo
      entró, a qué hora, a qué lugar y si hay foto.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <h2>Buscar en el registro</h2>

    <form class="buscador" @submit.prevent="buscar">
      <div class="campo">
        <label class="campo__etiqueta" for="patente">Patente</label>
        <input id="patente" v-model="busqueda.patente" type="text" maxlength="12">
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="desde">Desde</label>
        <input id="desde" v-model="busqueda.desde" type="date">
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="hasta">Hasta</label>
        <input id="hasta" v-model="busqueda.hasta" type="date">
      </div>

      <button type="submit" class="boton" :disabled="buscando">Buscar</button>
    </form>

    <div v-if="resultado" class="aviso aviso--info">
      <p v-if="!resultado.sesiones || resultado.sesiones.length === 0">
        No hay ninguna sesión con esa identificación en ese período. Si el
        cliente insiste, el problema probablemente sea que cambió la patente o
        que entró por otra sede.
      </p>
      <table v-else class="tabla">
        <thead>
          <tr>
            <th>Patente</th>
            <th>Entrada</th>
            <th>Salida</th>
            <th>Lugar</th>
            <th class="cifra">Permanencia</th>
            <th>Boleta</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="fila in resultado.sesiones" :key="fila.id">
            <td class="cifra">{{ fila.patente }}</td>
            <td>{{ momento(fila.ingreso_en) }}</td>
            <td>{{ fila.salida_en ? momento(fila.salida_en) : 'Dentro' }}</td>
            <td>{{ fila.lugar_codigo ?? '—' }}</td>
            <td class="cifra">{{ fila.segundos_permanencia ?? '—' }}</td>
            <td>{{ fila.boleta_id ? 'Emitida' : 'Sin boleta' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <h2>Reclamos de la sede</h2>

    <table class="tabla">
      <thead>
        <tr>
          <th>Folio</th>
          <th>Fecha del hecho</th>
          <th>Patente vista</th>
          <th>Estado</th>
          <th>Foto</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="reclamo in reclamos" :key="reclamo.id">
          <td><span class="cifra">{{ reclamo.folio }}</span></td>
          <td>{{ reclamo.fecha_incidente }}</td>
          <td class="cifra">{{ reclamo.patente_vista ?? '—' }}</td>
          <td>
            <span v-if="reclamo.estado === 'abierto'" class="estado estado--aviso">Abierto</span>
            <span v-else-if="reclamo.estado === 'en_revision'" class="estado estado--aviso">En revisión</span>
            <span v-else-if="reclamo.estado === 'resuelto'" class="estado estado--ok">Resuelto</span>
            <span v-else class="estado estado--neutro">Rechazado</span>
          </td>
          <td>{{ reclamo.tiene_foto ? 'Sí' : 'No' }}</td>
        </tr>
      </tbody>
    </table>

    <p class="pie">
      El listado no muestra el nombre, el RUT ni el teléfono del cliente. Eso
      está en la ficha completa, que requiere más rol, y se pide solo cuando
      hay que contactarlo.
    </p>
  </section>
</template>

<script setup lang="ts">
import type { Reclamo } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

interface SesionDelRegistro {
  id: string;
  patente: string;
  vin: string;
  lugar_codigo: string | null;
  ingreso_en: string;
  salida_en: string | null;
  segundos_permanencia: number | null;
  boleta_id: string | null;
}

const ruta = useRoute();

const parqueadero = computed(() => (typeof ruta.query.parqueadero === 'string' ? ruta.query.parqueadero : ''));

const busqueda = reactive({
  patente: '',
  desde: new Date().toISOString().slice(0, 10),
  hasta: new Date().toISOString().slice(0, 10),
});

const resultado = ref<{ sesiones?: SesionDelRegistro[] } | null>(null);
const reclamos = ref<Reclamo[]>([]);
const buscando = ref(false);
const error = ref<string | null>(null);

async function buscar() {
  error.value = null;
  buscando.value = true;
  resultado.value = null;

  try {
    resultado.value = await $fetch('/api/v1/reclamos/contexto', {
      query: {
        patente: busqueda.patente.toUpperCase(),
        desde: `${busqueda.desde} 00:00:00`,
        hasta: `${busqueda.hasta} 23:59:59`,
      },
    });
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo buscar en el registro.';
  } finally {
    buscando.value = false;
  }
}

onMounted(async () => {
  if (!parqueadero.value) {
    return;
  }

  try {
    const respuesta = await $fetch<{ reclamos: Reclamo[] }>(
      `/api/v1/sedes/${parqueadero.value}/reclamos`,
    );

    reclamos.value = respuesta.reclamos;
  } catch {
    error.value = 'No se pudieron cargar los reclamos.';
  }
});

function momento(instante: string): string {
  return new Date(instante).toLocaleString('es-CL', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

useHead({ title: 'Reclamos · Gestión de Estacionamiento' });
</script>

<style scoped>
.intro,
.pie {
  color: #52606d;
  max-width: 46rem;
}

.pie {
  font-size: 0.85rem;
  margin-top: 1.5rem;
}

.buscador {
  display: flex;
  align-items: flex-end;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

.buscador .campo {
  flex: 0 1 11rem;
  margin-bottom: 0;
}
</style>