<template>
  <section>
    <h1>Sedes</h1>

    <p class="intro">
      Elige la sede con la que vas a trabajar. Si el operador tiene una sola,
      puedes ir directo a la ocupación desde el panel.
    </p>

    <p v-if="cargando" class="aviso aviso--info">Cargando las sedes…</p>

    <p v-else-if="error" class="aviso aviso--error">{{ error }}</p>

    <p v-else-if="sedes.length === 0" class="aviso aviso--info">
      Este operador todavía no tiene sedes. Un administrador tiene que crear
      al menos una antes de poder operar.
    </p>

    <ul v-else class="sedes">
      <li v-for="sede in sedes" :key="sede.id" class="sede">
        <div class="sede__datos">
          <NuxtLink :to="`/ocupacion?parqueadero=${sede.id}`" class="sede__nombre">
            {{ sede.nombre }}
          </NuxtLink>
          <p v-if="sede.direccion" class="sede__direccion">{{ sede.direccion }}</p>
        </div>

        <div class="sede__cifras">
          <span class="cifra">{{ sede.capacidad_total }}</span>
          <span class="sede__unidad">lugares declarados</span>
        </div>

        <NuxtLink :to="`/ocupacion?parqueadero=${sede.id}`" class="boton">
          Ver ocupación
        </NuxtLink>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import type { Sede } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);
const error = ref<string | null>(null);
const sedes = ref<Sede[]>([]);

onMounted(async () => {
  try {
    const respuesta = await $fetch<{ sedes: Sede[] }>('/api/v1/sedes');

    sedes.value = respuesta.sedes;
  } catch {
    error.value = 'No se pudieron cargar las sedes de este operador.';
  } finally {
    cargando.value = false;
  }
});

useHead({ title: 'Sedes · Gestión de Estacionamiento' });
</script>

<style scoped>
.intro {
  color: #52606d;
  margin-bottom: 1.5rem;
}

.sedes {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.sede {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  background: #ffffff;
  border: 1px solid #d9e2ec;
  border-radius: 0.375rem;
  padding: 1rem 1.25rem;
}

.sede__datos {
  flex: 1 1 16rem;
}

.sede__nombre {
  font-weight: 600;
  color: #2b6cb0;
  text-decoration: none;
  font-size: 1.05rem;
}

.sede__direccion {
  margin: 0.2rem 0 0;
  font-size: 0.85rem;
  color: #627d98;
}

.sede__cifras {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.sede__cifras .cifra {
  font-size: 1.5rem;
  font-weight: 700;
}

.sede__unidad {
  font-size: 0.75rem;
  color: #627d98;
}
</style>