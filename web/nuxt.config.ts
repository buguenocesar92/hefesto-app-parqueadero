// ---------------------------------------------------------------------------
// Configuracion de Nuxt de Gestion de Estacionamientos.
// ---------------------------------------------------------------------------
//
// NUxt 3 CON RENDER EN EL SERVIDOR Y RUTAS EN `app/`
// ------------------------------------------------------
// El directorio de la aplicacion es `app/`, no `src/`. Es una decision de
// este proyecto, y por eso NO se usa `srcDir`: el codigo queda en `app/` y
// el proxy en `server/`, que es donde Nuxt espera encontrarlo.
//
// EL NAVEGADOR NO HABLA CON LA API
// --------------------------------
// El navegador nunca ve la URL de la API. Habla con este servidor, que
// reenvia (el patron BFF). Eso permite tres cosas que si no serian posibles:
//
//  1. La cookie de sesion viaja en el mismo origen, sin CORS.
//  2. La URL de la API no queda expuesta al visitante.
//  3. El token CSRF se reenvia como cabecera, sin que el navegador tenga que
//     leer una cookie httpOnly.
//
// Ver docs/01-ARQUITECTURA.md.
// ---------------------------------------------------------------------------

export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',

  devtools: { enabled: false },

  // Sin analitica de terceros: el sistema maneja datos de clientes.
  modules: [],

  css: ['~/assets/css/main.css'],

  typescript: {
    // ESTRICTO. Una respuesta de la API sin un campo esperando devuelve
    // `undefined`, y en pantalla eso es un `$500` en pantalla que nadie sabe
    // de donde salio. Mejor que lo diga el comprobador de tipos.
    strict: true,
    typeCheck: false,
  },

  runtimeConfig: {
    // `NUXT_API_BASE` es config PRIVADA: solo la usa el servidor de Nuxt
    // para reenviar las llamadas a la API. El navegador nunca la ve, y por
    // eso nunca se expone como `NUXT_PUBLIC_*`.
    apiBase: 'http://localhost:8000',
    public: {
      baseUrl: 'http://localhost:3000',
    },
  },

  nitro: {
    // Nuxt 3 espera el proxy en `server/api`. La carpeta `app/` es la
    // aplicacion, no el servidor.
    devProxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
        cookieDomainRewrite: '',
      },
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'es-CL' },
      title: 'Parqueadero · Hefesto Studio',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        {
          name: 'description',
          content: 'Gestion de estacionamientos de Hefesto Studio.',
        },
        // Este sistema muestra placas y nombres de clientes. Que no lo
        // indexe ningun buscador es una decision, no un descuido.
        { name: 'robots', content: 'noindex, nofollow' },
      ],
    },
  },
});