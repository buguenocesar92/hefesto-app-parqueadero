import {
  appendResponseHeader,
  defineEventHandler,
  getCookie,
  getMethod,
  getRequestHeader,
  getRequestURL,
  sendRedirect,
  setResponseStatus,
} from 'h3';

/**
 * BFF: reenvia `/api/**` a la API de Laravel.
 *
 * QUE HACE Y POR QUE
 * ------------------
 * 1. Copia las cookies de la peticion del navegador hacia la API. La cookie
 *    de sesion es HttpOnly: el navegador no la puede leer, pero si la puede
 *    reenviar, que es exactamente lo que hace este archivo.
 *
 * 2. Copia la cookie `XSRF-TOKEN` como cabecera `X-XSRF-TOKEN`. Laravel
 *    acepta esa cabecera para validar el token CSRF. Sin esto, toda escritura
 *    desde el navegador seria rechazada: marcar un ingreso o cobrar una salida
 *    son acciones que mueven datos de un cliente, y no pueden quedar sin
 *    proteccion.
 *
 * 3. Copia las cookies de la respuesta de vuelta, por ejemplo cuando la API
 *    renueva la sesion.
 *
 * 4. Redirige al navegador cuando la peticion es la respuesta de Authentik:
 *    el `callback` devuelve JSON, pero en el navegador tiene que ser una
 *    navegacion, no un JSON.
 *
 * El navegador nunca ve la URL interna de la API ni habla directo con ella.
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event);
  const url = getRequestURL(event);
  const metodo = getMethod(event).toUpperCase();

  // `/api/v1/salud` -> `v1/salud`
  const ruta = url.pathname.replace(/^\/api\/?/, '');

  const destino = new URL(`${String(config.apiBase).replace(/\/+$/, '')}/${ruta}`);
  destino.search = url.search;

  const cabeceras: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };

  const cookies = getRequestHeader(event, 'cookie');

  if (cookies) {
    cabeceras.cookie = cookies;
  }

  const tokenCsrf = getCookie(event, 'XSRF-TOKEN');

  if (tokenCsrf) {
    // Laravel guarda el valor de la cookie codificado en URL.
    cabeceras['X-XSRF-TOKEN'] = decodeURIComponent(tokenCsrf);
  }

  let respuesta: Response;

  try {
    respuesta = await fetch(destino.toString(), {
      method: metodo,
      headers: cabeceras,
      // El cuerpo se reenvia tal cual: la API sigue siendo la que valida.
      body: metodo === 'GET' || metodo === 'HEAD' ? undefined : await cuerpoDe(event),
      redirect: 'manual',
    });
  } catch (error) {
    setResponseStatus(event, 502);

    return {
      mensaje: 'No se pudo contactar a la API del estacionamiento. Intente en unos segundos.',
      detalle: error instanceof Error ? error.message : String(error),
    };
  }

  // La respuesta de Authentik es una navegacion, no un JSON.
  if (ruta === 'v1/acceso/callback' && metodo === 'GET') {
    return manejarCallbackDeAuthentik(event, respuesta);
  }

  propagarCookiesDeRespuesta(event, respuesta);

  setResponseStatus(event, respuesta.status);
  appendResponseHeader(
    event,
    'content-type',
    respuesta.headers.get('content-type') ?? 'application/json',
  );

  // La sesion no debe quedar en caches intermedias: el contenido depende de
  // quien este conectado y de que hay adentro de su estacionamiento.
  appendResponseHeader(event, 'cache-control', 'private, no-store');

  return await respuesta.text();
});

/**
 * El callback de Authentik se responde con una navegacion, no con un JSON.
 */
async function manejarCallbackDeAuthentik(
  event: Parameters<typeof sendRedirect>[0],
  respuesta: Response,
) {
  propagarCookiesDeRespuesta(event, respuesta);

  const cuerpo = await respuesta.text();

  if (!respuesta.ok) {
    let mensaje = 'No se pudo iniciar sesion.';

    try {
      const datos = JSON.parse(cuerpo) as { mensaje?: string };
      mensaje = datos.mensaje ?? mensaje;
    } catch {
      // Se deja el mensaje generico: la respuesta de la API no es JSON.
    }

    return sendRedirect(event, `/entrar?error=${encodeURIComponent(mensaje)}`, 302);
  }

  let destino = '/';

  try {
    const datos = JSON.parse(cuerpo) as { redirigir_a?: string };
    destino = datos.redirigir_a ?? '/';
  } catch {
    // Cuerpo ilegible: se vuelve al inicio, que ya exige sesion.
  }

  return sendRedirect(event, destino, 302);
}

/**
 * Reenvia las cookies que la API haya puesto o cambiado.
 *
 * Se usa `getSetCookie()` cuando existe, porque varias cookies no se pueden
 * juntar en una sola cabecera `set-cookie`.
 */
function propagarCookiesDeRespuesta(
  event: Parameters<typeof appendResponseHeader>[0],
  respuesta: Response,
) {
  const cabeceras = respuesta.headers as Headers & { getSetCookie?: () => string[] };

  const cookies = typeof cabeceras.getSetCookie === 'function'
    ? cabeceras.getSetCookie()
    : [respuesta.headers.get('set-cookie')].filter((valor): valor is string => Boolean(valor));

  for (const cookie of cookies) {
    appendResponseHeader(event, 'set-cookie', cookie);
  }
}

/**
 * Lee el cuerpo de la peticion como texto.
 */
async function cuerpoDe(event: Parameters<typeof getMethod>[0]): Promise<string | undefined> {
  const bruto = await event.req.text();

  return bruto.length > 0 ? bruto : undefined;
}