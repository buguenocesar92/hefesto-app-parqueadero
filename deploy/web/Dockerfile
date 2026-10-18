# ---------------------------------------------------------------------------
# Imagen de la capa web (Nuxt, salida en modo servidor)
# ---------------------------------------------------------------------------
# Nuxt con render en el servidor. El navegador NO habla nunca con la API: va a
# este contenedor, y este reenvia (BFF). Ver docs/01-ARQUITECTURA.md.
#
# La imagen de Node esta fijada por tag exacto. Falta fijarla tambien por
# digest SHA256 antes de produccion (docs/10-PRUEBAS-Y-ESTADO.md).
# ---------------------------------------------------------------------------

FROM node:24.22.0-alpine3.24 AS construccion

WORKDIR /app

# Se copian primero los manifiestos para reutilizar la capa de instalacion.
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund

COPY . .

# `nuxt build` genera .output/, que es el servidor independiente. Corre en
# tiempo de construccion: por eso el contenedor final no necesita Node, ni el
# codigo fuente, ni las dependencias.
RUN npm run build

# --- Imagen final -----------------------------------------------------------
FROM node:24.22.0-alpine3.24 AS app

WORKDIR /app

ENV NODE_ENV=production
ENV NITRO_PORT=3000
ENV NITRO_HOST=0.0.0.0

# `node` (el usuario que trae la imagen) corre sin privilegios.
COPY --from=construccion --chown=node:node /app/.output ./.output

USER node

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]