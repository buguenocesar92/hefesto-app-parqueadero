import type { Usuario } from '~/tipos/api';

/**
 * Sesion de la persona que esta operando el estacionamiento.
 *
 * EL ROL DECIDE QUE SE VE, NO QUE SE PUEDE HACER
 * ----------------------------------------------
 * El menu esconde los botones que el rol no va a poder usar. Eso es una
 * cortesia, no una proteccion: la autorizacion real la hace la API, que
 * devuelve 404 o 422 y no ejecuta nada.
 *
 * Si el menu se equivoca, el operador ve un boton que al apretarlo no hace
 * nada. Si la autorizacion se equivocara, un operario veria datos de otro
 * operador. El costo de equivocarse en el menu es mucho menor, y por eso
 * el menu puede simplificarse y la autorizacion no.
 */
export function useSesion() {
  const usuario = useState<Usuario | null>('sesion:usuario', () => null);
  const cargado = useState<boolean>('sesion:cargada', () => false);

  async function cargar(): Promise<Usuario | null> {
    if (cargado.value) {
      return usuario.value;
    }

    try {
      usuario.value = await $fetch<Usuario>('/api/v1/yo');
    } catch {
      // Sin sesion no es un error: es el estado normal de quien todavia no
      // entro. El middleware de ruta lo manda a `/entrar`.
      usuario.value = null;
    }

    cargado.value = true;

    return usuario.value;
  }

  async function salir(): Promise<void> {
    try {
      await $fetch('/api/v1/salir', { method: 'POST' });
    } finally {
      // Aunque la API falle, la sesion local se limpia: dejarla puesta
      // dejaria al operador viendo pantallas que ya no le corresponden.
      usuario.value = null;
      cargado.value = true;

      await navigateTo('/entrar');
    }
  }

  return {
    usuario,
    cargado,
    cargar,
    salir,
    esAdministrador: computed(() => usuario.value?.puede_administrar === true),
    esSupervision: computed(() => usuario.value?.rol === 'supervisor' || usuario.value?.puede_administrar === true),
    puedeOperar: computed(() => usuario.value?.puede_operar === true),
    puedeCobrar: computed(() => usuario.value?.puede_cobrar === true),
    /**
     * Quien solo lee. Oculta el boton de cobro, pero la API sigue siendo la
     * que decide: este valor es solo para no mostrar lo que va a fallar.
     */
    esLectura: computed(() => usuario.value?.rol === 'lectura'),
  };
}