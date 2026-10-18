# ---------------------------------------------------------------------------
# Imagen de la API (Laravel con PHP 8.4)
# ---------------------------------------------------------------------------
# Dos etapas: una para instalar dependencias y otra con el resultado. La
# imagen final no tiene ni compilador, ni el codigo fuente, ni las
# dependencias de desarrollo.
#
# LA FOTO DE NODE Y DE PHP ESTAN FIJADAS POR TAG EXACTO
# -------------------------------------------------------
# Un tag como `php:8.4-fpm-alpine` puede cambiar de contenido entre dos
# construcciones sin que cambie el nombre. Falta fijarlas tambien por digest
# SHA-256 antes de produccion: queda anotado en docs/10-PRUEBAS-Y-ESTADO.md.
# ---------------------------------------------------------------------------

# --- Etapa de dependencias ---------------------------------------------------
FROM php:8.4-fpm-alpine AS dependencias

# `docker-php-ext-install` viene con la imagen oficial. Se instala lo unico que
# este proyecto usa de verdad.
RUN apk add --no-cache \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        mbstring \
        opcache \
        zip \
        bcmath \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

# Se copian primero los manifiestos para reutilizar la capa de instalacion:
# mientras el composer.json no cambie, esta capa no se rehace.
COPY composer.json composer.lock* ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

# --- Etapa final -------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
        postgresql-libs \
        icu-libs \
        oniguruma \
        libzip \
        nginx \
        supervisor \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        mbstring \
        opcache \
        zip \
        bcmath \
    && apk del .build-deps \
    && rm -rf /tmp/*

# La hora del servidor es la de Chile. Un servidor en UTC mostraria el ingreso
# de un vehiculo con dos horas de diferencia de la que se ve en el cartel.
ENV TZ=America/Santiago

WORKDIR /var/www/html

COPY --from=dependencias /var/www/html/vendor ./vendor

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/fotos \
        bootstrap/cache \
        /var/log/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

# La aplicacion no corre como root. En un contenedor, root es la cuenta mas
# expuesta si alguien logra escribir en un volumen montado.
USER www-data

EXPOSE 9000

# php-fpm corre bajo supervisor, que tambien levanta el nginx interno. Ver
# docker/supervisord.conf.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]