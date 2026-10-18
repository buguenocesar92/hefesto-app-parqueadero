# 04 - Instalación

> **Nada de este procedimiento se ejecutó.** No había Docker, ni PHP, ni
> Composer en el equipo donde se escribió. Está escrito para que sea seguido
> tal cual, y los comandos que no se pudieron verificar están marcados como
> tales. Ver [10](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Qué hace falta

| Herramienta | Versión | Para qué |
|---|---|---|
| Docker | con `compose` v2 | Todo corre en contenedores |
| Docker Compose | v2 | Los dos archivos de `deploy/` |
| Un dominio que apunte al servidor | — | Para el TLS de Caddy |
| Una instancia de Authentik | — | La identidad. Opcionalmente la de Hefesto Studio. |

**No hace falta instalar PHP, Composer, Node ni PostgreSQL en el servidor.**
Todo va en contenedores. Esa es la idea del despliegue.

---

## 2. La estructura que hay que tener

El código de la aplicación está en `api/` y `web/`, y los archivos de
despliegue en `deploy/`. Los `Dockerfile` usan contextos separados, así que los
comandos se corren **desde la raíz de la aplicación**:

```sh
cd apps/parqueadero
```

---

## 3. Desarrollo

### 3.1 Preparar el `.env`

```sh
cp .env.example .env
```

Después, completar **como mínimo**:

| Variable | Por qué |
|---|---|
| `APP_KEY` | Firma la cookie de sesión. Sin esto no hay sesión. |
| `DB_MIGRATE_PASSWORD` | El compose lo exige y no arranca sin él. |
| `DB_PASSWORD` | El rol con el que se conecta la aplicación. |
| `AUTHENTIK_ISSUER` | Sin esto nadie puede entrar. |
| `AUTHENTIK_CLIENT_ID` | Ídem. |
| `AUTHENTIK_CLIENT_SECRET` | Ídem. |

Para el desarrollo local, si Authentik no está disponible todavía, se puede
dejar la verificación de acceso sin probar y entrar más adelante. **No** se
puede inventar un emisor: la pantalla de acceso simplemente no funcionará.

Generar la clave de la aplicación:

```sh
docker run --rm php:8.4-cli php artisan key:generate --show
```

### 3.2 Levantar

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d
```

Levanta cuatro cosas: PostgreSQL, Redis, la API en `localhost:8000` y la
interfaz en `localhost:3000`.

**En desarrollo sí se aplican las migraciones al arrancar**
(`EJECUTAR_MIGRACIONES=true`). Es cómodo y es el motivo por el que esa variable
existe.

### 3.3 Cargar un operador de ejemplo

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    exec api php artisan db:seed
```

Crea un operador de ejemplo con una sede de veinte lugares, cuatro tarifas,
dos mensualistas (una vigente y una vencida), dos vehículos adentro, uno que ya
salió y pagó, y un reclamo. Ver
[06](06-INGRESO-VEHICULO.md) para el recorrido.

Los RUT del ejemplo son ficticios y están marcados como tales. El dígito
verificador sí está calculado, para que no los rechace una validación de
formato.

> **Para entrar hace falta que la cuenta exista en Authentik.** El seeder crea
> la fila en `usuarios`, pero no crea la cuenta en el proveedor de identidad.
> Hay que dar de alta `admin@parqueadero-demo.hefestostudio.cl` en Authentik y
> anotar su `sub` en `usuarios.authentik_subject`.

---

## 4. Producción

### 4.1 Lo primero: el rol de la base

Este paso va antes que todo lo demás, porque es el que hace que el aislamiento
funcione.

```sql
-- Rol con el que se conecta la APLICACION. NO superusuario, NO BYPASSRLS.
CREATE ROLE parqueadero_app LOGIN PASSWORD 'una-contrasena-larga-y-aleatoria';
GRANT CONNECT ON DATABASE parqueadero TO parqueadero_app;
GRANT USAGE ON SCHEMA public TO parqueadero_app;

-- Rol con el que se MIGRA. Este sí puede ser el dueño de la base.
CREATE ROLE parqueadero_migra LOGIN PASSWORD 'otra-contrasena-distinta';
GRANT ALL ON DATABASE parqueadero TO parqueadero_migra;
```

**El error más caro de esta aplicación es darle a `parqueadero_app` el rol de
superusuario o `BYPASSRLS`.** Con cualquiera de los dos, la Row Level Security
queda como decorativa y el sistema despide datos de todos los operadores. Por
eso `parqueadero:verificar-aislamiento` lo comprueba en cada arranque y **el
contenedor no levanta si está mal**.

### 4.2 Preparar

```sh
cp .env.example .env
```

En `.env` hay que completar, sí o sí:

| Variable | Nota |
|---|---|
| `APP_KEY` | `openssl rand -base64 32` |
| `DB_MIGRATE_PASSWORD` | |
| `DB_PASSWORD` | **Distinta** de la del rol de migración. |
| `AUTHENTIK_*` | Las cuatro. |
| `ACME_CORREO` | Correo real para los avisos de caducidad del certificado. |
| `DIRECCION_RESGARDOS` | `/var/backups/parqueadero` |

Y **`APP_DEBUG` debe quedar en `false`**. Con `true` en producción, un error
muestra rutas internas y consultas SQL a cualquier visitante.

### 4.3 Crear el directorio de respaldos

El compose **no** lo crea, a propósito: un `mkdir -p` sobre una ruta mal
escrita deja el respaldo donde nadie lo busca.

```sh
sudo mkdir -p /var/backups/parqueadero
sudo chown "$(id -u):$(id -g)" /var/backups/parqueadero
```

### 4.4 Levantar

```sh
docker compose -f deploy/docker-compose.prod.yml --env-file .env up -d
```

Lo único que queda publicado es **Caddy, en 80 y 443**. PostgreSQL, Redis, la
API y la interfaz se hablan por la red interna del compose.

### 4.5 Aplicar las migraciones

**En producción las migraciones no se aplican al arrancar.** Se aplican a
mano, mirando el resultado:

```sh
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
    exec -e DB_USERNAME=parqueadero_migra api php artisan migrate --force
```

El motivo es concreto: un contenedor que migra solo al arrancar aplica cambios
de esquema sin que nadie lo haya decidido. En un despliegue, el que decide cuándo
cambia el esquema es una persona.

### 4.6 Verificar

Esto hay que correrlo **siempre**, antes de decir que el sistema está listo:

```sh
# 1. El aislamiento entre operadores.
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
    exec api php artisan parqueadero:verificar-aislamiento

# 2. Que el registro y las sesiones estén de acuerdo.
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
    exec api php artisan parqueadero:verificar-registro
```

El primero tiene que salir con cuatro `[OK]`. Si algo falla, **el sistema no
está listo para operar**, y el motivo va a estar en la salida.

### 4.7 El primer respaldo

Antes de abrir al público:

```sh
set -a; . ./.env; set +a
./deploy/respaldos/respaldo.sh
```

Y después **probar a restaurarlo** en otro lado. Un respaldo que nunca se
restauró no es un respaldo. Ver [08](08-RESPALDOS.md).

---

## 5. Las tareas de la casa

Son tres, y todas se lanzan desde el sistema, no desde dentro del contenedor.

| Tarea | Cuándo | Qué hace |
|---|---|---|
| `parqueadero:verificar-aislamiento` | En cada arranque | El entrypoint la invoca sola |
| `parqueadero:avisar-mensualidades` | Todos los días, temprano | Deja en bitácora las mensualidades vencidas y por vencer |
| `parqueadero:cerrar-jornada` | Todos los días, después de cerrar | Escribe la cuenta del día en bitácora |
| `deploy/respaldos/respaldo.sh` | Todos los días | El respaldo |

Con `cron` del sistema:

```cron
# Aviso de vencimientos, 07:00.
0 7 * * * cd /opt/parqueadero && set -a && . ./.env && set +a && docker compose -f deploy/docker-compose.prod.yml exec -T api php artisan parqueadero:avisar-mensualidades --directo

# Cierre del día, 23:30.
30 23 * * * cd /opt/parqueadero && set -a && . ./.env && set +a && docker compose -f deploy/docker-compose.prod.yml exec -T api php artisan parqueadero:cerrar-jornada --directo

# Respaldo, 02:00.
0 2 * * * cd /opt/parqueadero && set -a && . ./.env && set +a && ./deploy/respaldos/respaldo.sh >> /var/log/parqueadero-respaldo.log 2>&1
```

La opción `--directo` existe para instalaciones chicas que no tienen un worker
de colas levantado. Con worker, se quita y el trabajo va a la cola.

**Por qué desde el sistema y no desde dentro del contenedor:** el planificador
de Laravel corre dentro del contenedor de la API. Si el contenedor se reinicia
tres veces en la noche, la tarea corre tres veces. Con `cron`, correr una vez es
decisión de quien programa.

---

## 6. Las pruebas

```sh
cd api

# Con PostgreSQL de verdad, no con SQLite.
docker compose -f ../deploy/docker-compose.dev.yml --env-file ../.env \
    run --rm api php artisan test

# Solo el aislamiento, que es lo crítico.
docker compose -f ../deploy/docker-compose.dev.yml --env-file ../.env \
    run --rm api php artisan test --testsuite=Aislamiento
```

La base de pruebas se llama `parqueadero_test` y está **fija en `phpunit.xml`**.
Correr estas pruebas en SQLite daría verde sin comprobar nada, porque ni la RLS
ni el índice único parcial existen allí. Ver [10](10-PRUEBAS-Y-ESTADO.md).

Para crear la base de pruebas:

```sql
CREATE DATABASE parqueadero_test OWNER parqueadero_migra;
```

---

## 7. Problemas frecuentes

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| El contenedor de la API no levanta y dice "el aislamiento NO está garantizado" | El rol de la base es superusuario o tiene `BYPASSRLS` | Revisar `000014` y los permisos del rol |
| "No hay contexto de operador" en el log | Una consulta se ejecutó fuera de `ResolverTenant` | Buscar el `DB::table()` que faltaba en el middleware |
| `no existe la tabla ...` | Faltan migraciones | `php artisan migrate --force` |
| El certificado no se emite | El dominio no apunta al servidor, o el 80 está ocupado | `dig` del dominio, y ver `docker compose logs caddy` |
| El acceso no funciona | `AUTHENTIK_ISSUER` mal escrito | El emisor debe terminar en barra |
| La ocupación no cambia | Redis caído | Es degradado, no caído: se recalcula en cada consulta. Ver [SaludController] |
| Los lugares marcados libres están ocupados | Hay sesiones abiertas sin movimiento | `php artisan parqueadero:verificar-registro` |

---

## 8. Lo que hay que revisar antes de recibir operadores

1. `parqueadero:verificar-aislamiento` sale con cuatro `[OK]`.
2. `parqueadero:verificar-registro` no reporta incoherencias.
3. Un respaldo se corrió **y se restauró** en otro lado.
4. `APP_DEBUG=false` en producción.
5. El rol `parqueadero_app` **no** es superusuario.
6. Las tarifas están cargadas y vigentes. Sin tarifas, **no se puede cobrar
   ninguna salida**: es lo primero que se rompe.
7. Hay lugares cargados, y coinciden con la capacidad declarada.
8. Alguien de confianza probó un ingreso y una salida de punta a punta.

El punto 6 es el que más sorpresa causa: una sede sin tarifas parece andando
hasta que sale el primer cliente.