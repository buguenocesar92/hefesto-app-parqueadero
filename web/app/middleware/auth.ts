/**
 * Protege las pantallas del estacionamiento.
 *
 * Si no hay sesion, manda a la pantalla de entrada. No es una proteccion de
 * seguridad: la seguridad esta en la API, que no entrega datos de otro
 * operador y responde 404 ante un recurso que no le corresponde.
 *
 * Esto es solo para que el operador no vea una pantalla vacia sin saber que
 * hacer.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const { usuario, cargar } = useSesion();

  const quien = await cargar();

  if (!quien && to.path !== '/entrar') {
    return navigateTo('/entrar');
  }

  if (quien && to.path === '/entrar') {
    return navigateTo('/');
  }

  return usuario.value !== null || to.path === '/entrar';
});