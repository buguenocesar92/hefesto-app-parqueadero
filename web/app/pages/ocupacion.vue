<template>
  <section>
    <h1>Ocupación de la sede</h1>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <div v-if="estado" class="cifras">
      <div class="cifras__item">
        <div class="cifras__valor cifra">{{ estado.disponibles }}</div>
        <div class="cifras__etiqueta">Libres</div>
      </div>
      <div class="cifras__item">
        <div class="cifras__valor cifra">{{ estado.ocupados }}</div>
        <div class="cifras__etiqueta">Ocupados</div>
      </div>
      <div class="cifras__item">
        <div class="cifras__valor cifra">{{ estado.capacidad }}</div>
        <div class="cifras__etiqueta">Lugares activos</div>
      </div>
    </div>

    <!--
        La capacidad declarada y la real se muestran por separado aunque no
        coincidan. Elegir una de las dos y esconder la diferencia es como se
        termina prometiendo un puesto que no existe.
    -->
    <p v-if="estado && !estado.coincide" class="aviso aviso--aviso">
      <strong>Ojo:</strong> esta sede declara
      <span class="cifra">{{ estado.capacidad_declarada }}</span> lugares y tiene
      <span class="cifra">{{ estado.capacidad_real }}</span> lugares activos.
      Revisa la pantalla de lugares: si no coinciden, un cliente va a recibir
      un número que el estacionamiento no puede cumplir.
    </p>

    <p v-else-if="estado?.sobrecapacidad" class="aviso aviso--error">
      Hay más vehículos adentro que lugares activos. No es un error del
      sistema, pero hay que avisarlo al cliente y corregir la carga de
      lugares.
    </p>

    <h2>Lugares</h2>

    <p v-if="cargandoLugares" class="aviso aviso--info">Cargando los lugares…</p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Tipo</th>
          <th>Estado</th>
          <th>Patente</th>
          <th>Entró</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="lugar in lugares" :key="lugar.id">
          <td><strong>{{ lugar.codigo }}</strong></td>
          <td>
            {{ lugar.tipo_etiqueta }}
            <span v-if="lugar.reservado_para" class="detalle">
              — {{ lugar.reservado_para }}
            </span>
          </td>
          <td>
            <!-- El estado dice la palabra ademas del color. -->
            <span
              v-if="lugar.ocupado"
              class="estado estado--peligro"
            >Ocupado</span>
            <span v-else-if="lugar.activo" class="estado estado--ok">Libre</span>
            <span v-else class="estado estado--neutro">Inactivo</span>
          </td>
          <td class="cifra">{{ lugar.patente ?? '—' }}</td>
          <td>{{ lugar.ingreso_en ? hora(lugar.ingreso_en) : '—' }}</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { LugarConEstado, OcupacionSede } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const ruta = useRoute();

const parqueadero = computed(() => (typeof ruta.query.parqueadero === 'string' ? ruta.query.parqueadero : ''));

const estado = ref<OcupacionSede | null>(null);
const lugares = ref<LugarConEstado[]>([]);
const cargandoLugares = ref(true);
const error = ref<string | null>(null);

async function cargar() {
  error.value = null;

  if (!parqueadero.value) {
    error.value = 'Falta indicar la sede. Vuelve al inicio y elige una.';
    return;
  }

  try {
    const [estadoRespuesta, lugaresRespuesta] = await Promise.all([
      $fetch<OcupacionSede>(`/api/v1/sedes/${parqueadero.value}/ocupacion`),
      $fetch<{ lugares: LugarConEstado[] }>(`/api/v1/sedes/${parqueadero.value}/ocupacion/lugares`),
    ]);

    estado.value = estadoRespuesta;
    lugares.value = lugaresRespuesta.lugares;
  } catch {
    error.value = 'No se pudo leer la ocupación de esta sede.';
  } finally {
    cargandoLugares.value = false;
  }
}

await cargar();

useHead({ title: 'Ocupación · Gestión de Estacionamiento' });
</script>

<style scoped>
.detalle {
  font-size: 0.8rem;
  color: #627d98;
}
</style>