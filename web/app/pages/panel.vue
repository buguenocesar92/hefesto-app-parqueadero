<template>
  <section>
    <h1>Panel de una sede</h1>

    <p class="intro">
      Lo que se necesita para decidir qué hacer en los próximos diez minutos.
      Un panel que muestra veinte números deja de usarse, y un panel que no se
      usa no sirve de nada.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <p v-if="cargando" class="aviso aviso--info">Cargando el panel…</p>

    <template v-else-if="panel">
      <h2>{{ panel.parqueadero.nombre }}</h2>

      <div class="cifras">
        <div class="cifras__item">
          <div class="cifras__valor cifra">{{ panel.ocupacion.disponibles }}</div>
          <div class="cifras__etiqueta">Libres</div>
        </div>
        <div class="cifras__item">
          <div class="cifras__valor cifra">{{ panel.ocupacion.ocupados }}</div>
          <div class="cifras__etiqueta">Ocupados</div>
        </div>
        <div class="cifras__item">
          <div class="cifras__valor cifra">$ {{ panel.hoy.total.toLocaleString('es-CL') }}</div>
          <div class="cifras__etiqueta">Cobrado hoy</div>
        </div>
      </div>

      <h2>Hoy</h2>

      <table class="tabla">
        <tbody>
          <tr>
            <th>Entradas</th>
            <td class="cifra">{{ panel.hoy.entradas }}</td>
          </tr>
          <tr>
            <th>Salidas</th>
            <td class="cifra">{{ panel.hoy.salidas }}</td>
          </tr>
          <tr>
            <th>Boletas emitidas</th>
            <td class="cifra">{{ panel.hoy.boletas }}</td>
          </tr>
          <tr>
            <th>Boletas anuladas</th>
            <td class="cifra">{{ panel.hoy.boletas_anuladas }}</td>
          </tr>
        </tbody>
      </table>

      <h2>Pendientes</h2>

      <table class="tabla">
        <tbody>
          <tr>
            <th>Reclamos abiertos</th>
            <td class="cifra">{{ panel.reclamos.abiertos }}</td>
          </tr>
          <tr>
            <th>Reclamos en revisión</th>
            <td class="cifra">{{ panel.reclamos.en_revision }}</td>
          </tr>
          <tr>
            <th>Mensualidades activas</th>
            <td class="cifra">{{ panel.mensualidades.activas }}</td>
          </tr>
          <tr>
            <th>Mensualidades por vencer</th>
            <td class="cifra">{{ panel.mensualidades.por_vencer }}</td>
          </tr>
        </tbody>
      </table>

      <h2>Configuración del operador</h2>

      <table class="tabla">
        <tbody>
          <tr>
            <th>Minutos de cortesía</th>
            <td>
              <span class="cifra">{{ configuracion?.minutos_cortesia ?? '—' }}</span>
              <small v-if="configuracion && !configuracion.minutos_cortesia_en_uso" class="nota">
                — guardado, pero el cobro usa los minutos por tarifa de cada tarifa,
                no este valor.
              </small>
            </td>
          </tr>
          <tr>
            <th>Mensualidad vencida bloquea acceso</th>
            <td>
              <span v-if="configuracion?.mensualidad_vencida_bloquea_acceso" class="estado estado--aviso">
                Marca y avisa
              </span>
              <span v-else class="estado estado--neutro">Solo avisa</span>
            </td>
          </tr>
          <tr>
            <th>Zona horaria</th>
            <td>{{ configuracion?.zona_horaria ?? '—' }}</td>
          </tr>
        </tbody>
      </table>

      <p class="pie">
        La hora del servidor es {{ configuracion?.hora_del_servidor ?? '—' }}. Todos los
        cobros usan esa hora y no la del equipo del operador: si un equipo tiene la
        reloj mal puesta, el cobro no cambia.
      </p>
    </template>
  </section>
</template>

<script setup lang="ts">
import type { Configuracion, PanelSede } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const ruta = useRoute();

const parqueadero = computed(() => (typeof ruta.query.parqueadero === 'string' ? ruta.query.parqueadero : ''));

const panel = ref<PanelSede | null>(null);
const configuracion = ref<Configuracion | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
  if (!parqueadero.value) {
    error.value = 'Falta indicar la sede. Vuelve al inicio y elige una.';
    cargando.value = false;
    return;
  }

  try {
    const [panelRespuesta, configuracionRespuesta] = await Promise.all([
      $fetch<PanelSede>(`/api/v1/sedes/${parqueadero.value}/panel`),
      $fetch<Configuracion>('/api/v1/configuracion'),
    ]);

    panel.value = panelRespuesta;
    configuracion.value = configuracionRespuesta;
  } catch {
    error.value = 'No se pudo cargar el panel de esta sede.';
  } finally {
    cargando.value = false;
  }
});

useHead({ title: 'Panel · Gestión de Estacionamiento' });
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

.nota {
  display: block;
  font-size: 0.78rem;
  color: #627d98;
  max-width: 30rem;
}
</style>