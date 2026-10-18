<template>
  <section class="entrar">
    <h1>Gestión de Estacionamiento</h1>

    <p class="entrar__intro">
      El acceso es con la cuenta corporativa. Si no puedes entrar, avisa al
      administrador del estacionamiento: el rol que decides en qué puedes
      trabajar se configura en el servidor de identidad de Hefesto Studio.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ error }}</p>

    <a class="boton boton--principal" href="/api/v1/acceso">Entrar con la cuenta corporativa</a>

    <p class="entrar__nota">
      El acceso se hace en el servidor de identidad de Hefesto Studio.
      Esta aplicación no guarda contraseñas: por eso no hay forma de
      recuperar una por correo. Si no recuerdas la tuya, se cambia en
      Authentik, no aquí.
    </p>
  </section>
</template>

<script setup lang="ts">
definePageMeta({ layout: false });

const ruta = useRoute();

const error = computed(() => {
  const valor = ruta.query.error;

  return typeof valor === 'string' ? valor : null;
});

useHead({ title: 'Entrar · Gestión de Estacionamiento' });
</script>

<style scoped>
.entrar {
  max-width: 34rem;
  margin: 3rem auto;
  padding: 0 1.5rem;
  text-align: center;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: #1f2933;
}

.entrar__intro {
  color: #52606d;
  line-height: 1.6;
}

.entrar__nota {
  margin-top: 2rem;
  font-size: 0.8rem;
  color: #7b8794;
}
</style>