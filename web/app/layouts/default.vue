<template>
  <div class="parqueadero">
    <header class="parqueadero__cabecera">
      <div class="parqueadero__marca">
        <strong>Estacionamiento</strong>
        <span v-if="usuario" class="parqueadero__empresa">{{ usuario.empresa }}</span>
      </div>

      <nav v-if="usuario" class="parqueadero__menu">
        <NuxtLink to="/">Sedes</NuxtLink>
        <NuxtLink to="/ocupacion">Ocupación</NuxtLink>
        <NuxtLink v-if="puedeOperar" to="/ingresos">Ingresos</NuxtLink>
        <NuxtLink v-if="puedeOperar" to="/salidas">Salidas y cobro</NuxtLink>
        <NuxtLink to="/boletas">Boletas</NuxtLink>
        <NuxtLink v-if="puedeOperar" to="/lugares">Lugares</NuxtLink>
        <NuxtLink v-if="puedeCobrar" to="/mensualidades">Mensualidades</NuxtLink>
        <NuxtLink v-if="puedeOperar" to="/tarifas">Tarifas</NuxtLink>
        <NuxtLink to="/reclamos">Reclamos</NuxtLink>
        <NuxtLink v-if="esAdministrador" to="/panel">Administración</NuxtLink>
      </nav>

      <div v-if="usuario" class="parqueadero__usuario">
        <span>{{ usuario.nombre }} ({{ usuario.rol_etiqueta }})</span>
        <button type="button" class="boton boton--discreto" @click="salir">Salir</button>
      </div>
    </header>

    <main class="parqueadero__contenido">
      <slot />
    </main>

    <footer class="parqueadero__pie">
      <p>
        Aplicación de Hefesto Studio. El registro del estacionamiento no se edita:
        se anota al lado. Un reclamo se responde con lo que dice el registro, no con
        lo que se recuerda.
      </p>
    </footer>
  </div>
</template>

<script setup lang="ts">
const { usuario, cargar, salir, puedeOperar, puedeCobrar, esAdministrador } = useSesion();

// El menu depende de quien esta conectado. Se carga una sola vez por visita:
// el resto de las paginas ya la piden por su cuenta con el middleware.
await callOnce('cargar-sesion', cargar);
</script>

<style scoped>
.parqueadero {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: #1f2933;
}

.parqueadero__cabecera {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #d9e2ec;
  background: #ffffff;
}

.parqueadero__marca {
  display: flex;
  flex-direction: column;
}

.parqueadero__empresa {
  font-size: 0.85rem;
  color: #627d98;
}

.parqueadero__menu {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

.parqueadero__menu a {
  color: #334e68;
  text-decoration: none;
  padding: 0.25rem 0;
  border-bottom: 2px solid transparent;
}

.parqueadero__menu a.router-link-active {
  border-bottom-color: #2b6cb0;
  font-weight: 600;
}

.parqueadero__usuario {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.9rem;
}

.parqueadero__contenido {
  flex: 1;
  width: 100%;
  max-width: 72rem;
  margin: 0 auto;
  padding: 1.5rem;
}

.parqueadero__pie {
  border-top: 1px solid #d9e2ec;
  padding: 1rem 1.5rem;
  font-size: 0.8rem;
  color: #627d98;
}
</style>