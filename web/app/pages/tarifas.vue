<template>
  <section>
    <h1>Tarifas</h1>

    <p class="intro">
      Solo hay dos tipos de tarifa, y son los que el cálculo entiende:
      <strong>por tiempo</strong>, que cobra una fracción cada certainos
      minutos, y <strong>precio fijo</strong>, que no depende de las horas.
      Un tipo que el cálculo no conoce haría fallar el cobro en la barrera,
      con el cliente esperando.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <h2>Vigentes</h2>

    <table class="tabla">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Tipo</th>
          <th class="cifra">Valor</th>
          <th class="cifra">Minutos por fracción</th>
          <th class="cifra">Cortesía</th>
          <th>Vigencia</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="tarifa in tarifas" :key="tarifa.id">
          <td>
            {{ tarifa.nombre }}
            <small v-if="!tarifa.completa" class="incompleta">
              — le falta los minutos por fracción
            </small>
          </td>
          <td>{{ tarifa.tipo_etiqueta }}</td>
          <td class="cifra">$ {{ tarifa.valor.toLocaleString('es-CL') }}</td>
          <td class="cifra">{{ tarifa.minutos_por_fraccion ?? '—' }}</td>
          <td class="cifra">{{ tarifa.minutos_incluidos }} min</td>
          <td>
            {{ dia(tarifa.vigente_desde) }}
            <span v-if="tarifa.vigente_hasta" class="detalle">
              hasta {{ dia(tarifa.vigente_hasta) }}
            </span>
          </td>
          <td>
            <span v-if="tarifa.activo" class="estado estado--ok">Activa</span>
            <span v-else class="estado estado--neutro">Terminada</span>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="pie">
      Las tarifas no se editan una vez cobraron. Para cambiar el precio se
      crea una nueva y se termina la anterior: un vehículo que entró antes del
      cambio se cobra con el precio de ese día, y esa boleta tiene que seguir
      explicando lo mismo dentro de un año.
    </p>
  </section>
</template>

<script setup lang="ts">
import type { Tarifa } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const ruta = useRoute();

const parqueadero = computed(() => (typeof ruta.query.parqueadero === 'string' ? ruta.query.parqueadero : ''));

const tarifas = ref<Tarifa[]>([]);
const error = ref<string | null>(null);

onMounted(async () => {
  if (!parqueadero.value) {
    error.value = 'Falta indicar la sede. Vuelve al inicio y elige una.';
    return;
  }

  try {
    const respuesta = await $fetch<{ tarifas: Tarifa[] }>(
      `/api/v1/sedes/${parqueadero.value}/tarifas`,
    );

    tarifas.value = respuesta.tarifas;
  } catch {
    error.value = 'No se pudieron cargar las tarifas.';
  }
});

function dia(instante: string): string {
  return new Date(instante).toLocaleDateString('es-CL');
}

useHead({ title: 'Tarifas · Gestión de Estacionamiento' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 46rem;
}

.incompleta {
  display: block;
  color: #9b2c2c;
  font-size: 0.78rem;
}

.detalle {
  display: block;
  font-size: 0.78rem;
  color: #627d98;
}

.pie {
  margin-top: 1.5rem;
  font-size: 0.85rem;
  color: #52606d;
  max-width: 46rem;
}
</style>