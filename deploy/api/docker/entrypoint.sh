#!/bin/sh
# ---------------------------------------------------------------------------
# Arranque del contenedor de la API.
# ---------------------------------------------------------------------------
# HAY TRES PASOS Y EL ORDEN NO ES NEGOCIABLE
# ------------------------------------------
# 1. Esperar a que PostgreSQL responda. Sin esto, el primer arranque falla y el
#    orquestador lo reintenta a ciegas.
# 2. Migrar, SOLO si se pide. En una instalacion normal las migraciones las
#    aplica una persona, a mano, mirando el resultado. Un contenedor que
#    migra solo al arrancar es un contenedor que aplica cambios de esquema sin
#    que nadie lo haya decidido.
# 3. Verificar el aislamiento. Si la Row Level Security no esta puesta, la API
#    NO levanta: es preferible que el sistema no funcione a que funcione
#    despidiendo datos de otro operador.
#
# El nginx interno y php-fpm los levanta supervisor, no este script.
# ---------------------------------------------------------------------------

set -eu

ESPERA_MAXIMA="${ESPERA_MAXIMA_SEGUNDOS:-60}"

esperar_base() {
    echo "[entrada] esperando a PostgreSQL en ${DB_HOST}:${DB_PORT:-5432}..."

    SEGUNDOS=0

    while [ "$SEGUNDOS" -lt "$ESPERA_MAXIMA" ]; do
        if php -r '
            $host = getenv("DB_HOST");
            $puerto = getenv("DB_PORT") ?: "5432";
            try {
                $socket = @fsockopen($host, (int) $puerto, $codigo, $mensaje, 2);
                if ($socket === false) {
                    exit(1);
                }
                fclose($socket);
                exit(0);
            } catch (Throwable $error) {
                exit(1);
            }
        ' 2>/dev/null; then
            echo "[entrada] PostgreSQL responde."
            return 0
        fi

        SEGUNDOS=$((SEGUNDOS + 2))
        sleep 2
    done

    echo "[entrada] ERROR: PostgreSQL no respondio en $ESPERA_MAXIMA segundos."
    return 1
}

esperar_base

# --- Migraciones ------------------------------------------------------------
# Se migra con un rol aparte, que puede ser el dueno de la base. La aplicacion
# se conecta con DB_USERNAME, que es un rol normalizado y sin permisos de mas.
if [ "${EJECUTAR_MIGRACIONES:-false}" = "true" ]; then
    echo "[entrada] EJECUTAR_MIGRACIONES=true: aplicando migraciones..."

    if php artisan migrate --force; then
        echo "[entrada] migraciones aplicadas."
    else
        echo "[entrada] ERROR: las migraciones fallaron. La API NO arranca."
        exit 1
    fi
else
    echo "[entrada] migraciones omitidas (EJECUTAR_MIGRACIONES no es true)."
    echo "[entrada] apliquelas a mano con: php artisan migrate --force"
fi

# --- Aislamiento entre operadores -------------------------------------------
# La comprobacion es la que decide si el servicio arranca. Un servicio parado
# es un problema visible; uno que mezcla datos de dos operadores es un problema
# que aparece meses despues, en un reclamo.
if [ "${PARQUEADERO_VERIFICAR_AISLAMIENTO:-true}" = "true" ]; then
    echo "[entrada] verificando el aislamiento entre operadores..."

    if php artisan parqueadero:verificar-aislamiento; then
        echo "[entrada] aislamiento verificado."
    else
        echo "[entrada] ERROR: el aislamiento NO esta garantizado. La API NO arranca."
        echo "[entrada]        revise el rol de la base: no debe ser superusuario"
        echo "[entrada]        ni tener BYPASSRLS. Ver docs/03-MULTITENANCY-Y-ROLES.md."
        exit 1
    fi
else
    echo "[entrada] AVISO: se salteo la verificacion del aislamiento."
    echo "[entrada]        dejaste PARQUEADERO_VERIFICAR_AISLAMIENTO en false."
fi

# --- Limpieza de caches -----------------------------------------------------
# Se limpian porque la imagen las trae compiladas de otra configuracion.
php artisan config:clear
php artisan route:clear
php artisan view:clear 2>/dev/null || true

echo "[entrada] listo. supervisor levanta php-fpm y nginx."

# `exec` deja a supervisor como proceso principal: sin el, el contenedor usa su
# shell como proceso 1 y las senales no llegan a php-fpm.
exec /usr/bin/supervisord -c /etc/supervisord.conf