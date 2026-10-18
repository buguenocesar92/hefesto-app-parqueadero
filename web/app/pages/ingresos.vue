<template>
  <section>
    <h1>Marcar un ingreso</h1>

    <p class="intro">
      El lugar se elige a mano. El sistema no lo busca solo: si lo hiciera, el
      operador marcaría el ingreso sin saber dónde quedó el vehículo, y eso
      se descubre cuando el cliente no lo encuentra.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <p v-if="revision && revision.avisos.length > 0" class="aviso aviso--aviso">
      <ul class="avisos">
        <li v-for="aviso in revision.avisos" :key="aviso">{{ aviso }}</li>
      </ul>
    </p>

    <p v-if="exito" class="aviso aviso--ok">
      Ingreso registrado en el lugar <strong>{{ exito.lugar_codigo }}</strong>.
      Referencia del ticket: <span class="cifra">{{ exito.referencia_ticket }}</span>.
    </p>

    <form class="formulario" @submit.prevent="ingresar">
      <div class="rejilla">
        <div class="campo">
          <label class="campo__etiqueta" for="patente">Patente</label>
          <input
            id="patente"
            v-model="formulario.patente"
            type="text"
            autocomplete="off"
            maxlength="12"
            required
            @blur="revisar"
          >
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="vin">VIN</label>
          <input
            id="vin"
            v-model="formulario.vin"
            type="text"
            autocomplete="off"
            maxlength="17"
            required
            @blur="revisar"
          >
          <small class="ayuda">17 caracteres. No lleva I, O ni Q.</small>
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="tipo">Tipo de vehículo</label>
          <select id="tipo" v-model="formulario.tipo_vehiculo" required>
            <option v-for="tipo in tipos" :key="tipo.value" :value="tipo.value">
              {{ tipo.etiqueta }}
            </option>
          </select>
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="lugar">Lugar</label>
          <select id="lugar" v-model="formulario.lugar_id" required>
            <option value="" disabled>Elegir un lugar libre</option>
            <option v-for="lugar in libres" :key="lugar.id" :value="lugar.id">
              {{ lugar.codigo }} — {{ lugar.tipo_etiqueta }}
            </option>
          </select>
          <small class="ayuda">
            Solo lugares libres. La lista viene de las sesiones abiertas, no de
            un campo guardado.
          </small>
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="marca">Marca</label>
          <input id="marca" v-model="formulario.marca" type="text" maxlength="60">
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="modelo">Modelo</label>
          <input id="modelo" v-model="formulario.modelo" type="text" maxlength="60">
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="color">Color</label>
          <input id="color" v-model="formulario.color" type="text" maxlength="40">
        </div>
      </div>

      <button type="submit" class="boton boton--principal" :disabled="enviando">
        {{ enviando ? 'Registrando…' : 'Marcar ingreso' }}
      </button>
    </form>
  </section>
</template>

<script setup lang="ts">
import type { LugarConEstado, RevisionAcceso, Sesion } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const tipos = [
  { value: 'automobile', etiqueta: 'Automóvil' },
  { value: 'camioneta', etiqueta: 'Camioneta' },
  { value: 'moto', etiqueta: 'Motocicleta' },
  { value: 'camion', etiqueta: 'Camión' },
  { value: 'furgon', etiqueta: 'Furgón' },
];

const ruta = useRoute();
const parqueadero = computed(() => (typeof ruta.query.parqueadero === 'string' ? ruta.query.parqueadero : ''));

const formulario = reactive({
  patente: '',
  vin: '',
  tipo_vehiculo: 'automobile',
  lugar_id: '',
  marca: '',
  modelo: '',
  color: '',
});

const libres = ref<LugarConEstado[]>([]);
const revision = ref<RevisionAcceso | null>(null);
const exito = ref<Sesion | null>(null);
const error = ref<string | null>(null);
const enviando = ref(false);

async function cargarLugares() {
  if (!parqueadero.value) {
    error.value = 'Falta indicar la sede. Vuelve al inicio y elige una.';
    return;
  }

  const respuesta = await $fetch<{ lugares: LugarConEstado[] }>(
    `/api/v1/sedes/${parqueadero.value}/ocupacion/lugares`,
  );

  libres.value = respuesta.lugares.filter((lugar) => lugar.activo && !lugar.ocupado);
}

/**
 * Revisa el acceso antes de marcar.
 *
 * Esto es un AVISO. Si el sistema decidiera bloquear, dejaria al operador sin
 * poder abrir la puerta en una emergencia, y esa decisión no le corresponde a
 * un sistema. La puerta la abre una persona.
 */
async function revisar() {
  revision.value = null;

  if (formulario.vin.length !== 17) {
    return;
  }

  try {
    revision.value = await $fetch<RevisionAcceso>('/api/v1/ingresos/revisar', {
      method: 'POST',
      body: { vin: formulario.vin.toUpperCase(), patente: formulario.patente },
    });
  } catch {
    // Si la revisión falla, el ingreso sigue: es una ayuda, no un requisito.
    revision.value = null;
  }
}

async function ingresar() {
  error.value = null;
  exito.value = null;
  enviando.value = true;

  try {
    const respuesta = await $fetch<{ sesion: Sesion }>('/api/v1/ingresos', {
      method: 'POST',
      body: { ...formulario },
    });

    exito.value = respuesta.sesion;

    // Se limpian solo los datos que identifican al vehículo. El lugar queda
    // en el selector, que se recarga: es lo que el operador cambia.
    formulario.patente = '';
    formulario.vin = '';
    revision.value = null;

    await cargarLugares();
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje
      ?? 'No se pudo registrar el ingreso. Si el lugar estaba tomado, elige otro.';
  } finally {
    enviando.value = false;
  }
}

onMounted(cargarLugares);

useHead({ title: 'Ingresos · Gestión de Estacionamiento' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 46rem;
}

.formulario {
  margin-top: 1rem;
}

.rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0 1rem;
}

.ayuda {
  font-size: 0.78rem;
  color: #627d98;
}

.avisos {
  margin: 0;
  padding-left: 1.2rem;
}
</style>