<template>
  <section>
    <h1>Lugares de la sede</h1>

    <p class="intro">
      El código es lo que está pintado en el suelo. No se cambia: si está
      equivocado, se desactiva este lugar y se crea otro con el código
      correcto, para que el registro de movimientos siga funcionando.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <p v-if="esAdministrador" class="aviso aviso--info">
      La cantidad de lugares es lo que el sistema promete al cliente. Si no
      coincide con lo declarado en la sede, el primer día lleno aparece el
      reclamo.
    </p>

    <table class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Tipo</th>
          <th>Reservado para</th>
          <th>Estado</th>
          <th v-if="esSupervision">Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="lugar in lugares" :key="lugar.id">
          <td><strong class="cifra">{{ lugar.codigo }}</strong></td>
          <td>{{ lugar.tipo_etiqueta }}</td>
          <td>{{ lugar.reservado_para ?? '—' }}</td>
          <td>
            <span v-if="lugar.activo" class="estado estado--ok">Activo</span>
            <span v-else class="estado estado--neutro">Inactivo</span>
          </td>
          <td v-if="esSupervision">
            <button
              v-if="lugar.activo"
              type="button"
              class="boton boton--discreto"
              @click="desactivar(lugar)"
            >
              Desactivar
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { Lugar } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const { esAdministrador, esSupervision } = useSesion();
const ruta = useRoute();

const parqueadero = computed(() => (typeof ruta.query.parqueadero === 'string' ? ruta.query.parqueadero : ''));

const lugares = ref<Lugar[]>([]);
const error = ref<string | null>(null);

async function cargar() {
  if (!parqueadero.value) {
    error.value = 'Falta indicar la sede. Vuelve al inicio y elige una.';
    return;
  }

  try {
    const respuesta = await $fetch<{ lugares: Lugar[] }>(
      `/api/v1/sedes/${parqueadero.value}/lugares`,
    );

    lugares.value = respuesta.lugares;
  } catch {
    error.value = 'No se pudieron cargar los lugares.';
  }
}

/**
 * Desactivar un lugar con un vehículo adentro se rechaza.
 *
 * La pantalla avisa antes de preguntar, porque la respuesta "tiene un
 * vehículo adentro" llega después de que el operador ya decidió.
 */
async function desactivar(lugar: Lugar) {
  const confirmado = window.confirm(
    `¿Desactivar el lugar ${lugar.codigo}? Si tiene un vehículo adentro, la API lo va a rechazar.`,
  );

  if (!confirmado) {
    return;
  }

  try {
    await $fetch(`/api/v1/lugares/${lugar.id}`, { method: 'DELETE' });

    await cargar();
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'El lugar no se pudo desactivar.';
  }
}

onMounted(cargar);

useHead({ title: 'Lugares · Gestión de Estacionamiento' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 46rem;
}
</style>