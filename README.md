# Gestión de Estacionamiento

Aplicación propia de Hefesto Studio para administrar un **estacionamiento
físico**: ingresos, salidas, cobro, mensualidades, control de lugares y
reclamos.

No es un kit de despliegue de una herramienta de terceros. El código es
original y está en este repositorio.

---

## El problema que resuelve

El problema de un estacionamiento no es saber cuántos lugares hay. Es
**saber si ese lugar está libre ahora**, con tres operarios marcando ingresos
al mismo tiempo en la misma puerta.

El segundo es silencioso y caro: **la cuenta del cobro**. El cliente pierde el
ticket y vuelve al día siguiente a pedir que le demuestren que pagó. Si esa
cuenta no se puede reconstruir desde el registro, es una discusión sin salida.

El tercero es el que aparece meses después: "estacioné el martes a la tarde y
me cobraron mal". Sin el registro de ese día, no hay respuesta, y la respuesta
es lo que sostiene la relación con el cliente.

Esta aplicación responde a los tres.

---

## Las cuatro ideas que definen el producto

### 1. Un lugar, a lo sumo un vehículo adentro

No hay ningún campo `ocupado`. Un lugar está ocupado **si existe una sesión
abierta con ese `lugar_id`**, y lo garantiza un **índice único parcial** en
PostgreSQL.

Dos operarios pueden apretar el botón a la vez: uno entra y el otro recibe un
error que el sistema traduce a "ese lugar se tomó hace un instante", con el
lugar libre más cercano. **El operario elige, no el sistema.**

La alternativa obvia —una columna `ocupado`— es el error clásico: dos personas
leen `false` al mismo tiempo, las dos escriben `true`, y queda **una sola
sesión para dos vehículos**. El conteo dice "1 ocupado" mientras hay dos autos
dentro.

### 2. El registro no se edita

El movimiento de un ingreso y el de una salida son la evidencia de un reclamo y
la explicación de una boleta dentro de un año.

No se pueden modificar ni borrar, y **no por una convención del código**: hay un
**gatillo en PostgreSQL** que rechaza el `UPDATE` y el `DELETE`. Un `psql` con
la contraseña de la base tampoco puede reescribir el pasado de un
estacionamiento.

Para corregir algo se **anota al lado** o se escribe un movimiento de anulación.

### 3. El tiempo que se cobra sale de la base

Nunca de lo que dice el cliente. Las dos marcas —entrada y salida— están en la
base, y la boleta se arma desde ahí.

Si el cliente pierde el ticket, la cuenta **se reconstruye igual**: mismo tiempo,
misma tarifa del día del ingreso, mismo total.

### 4. La tarifa del día del ingreso

Un auto que entró antes de un aumento se cobra con el precio de ese día. Por eso
las tarifas no se editan una vez cobraron: se crea una nueva y se cierra la
anterior. La vieja queda, con el valor con el que se cobró.

---

## Aviso importante

**Este sistema no cierra ninguna puerta.**

Una mensualidad vencida produce un **aviso**. La barrera la abre una persona.
Un sistema que cerrara el portón por una regla dejaría al operario sin poder
abrir en una emergencia, y esa decisión no le corresponde a un programa.

Y antes de poner esto a producir, lee
[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md).

---

## Estado

**Código escrito, sin ejecutar.** No había PHP, ni Composer, ni Node, ni Docker,
ni PostgreSQL en el equipo donde se escribió.

No se ejecutó **ni una migración, ni una prueba, ni una construcción de
imagen**. Cero. El sistema **no se ha visto funcionando**.

Lo que sí se hizo:

- Los tres scripts de shell validados con `bash -n`.
- Los dos archivos de compose validados con un analizador de YAML.
- Barrido de secretos: ningún valor real.
- Barrido de caracteres: sin CJK, cirílico ni otros alfabetos.
- Revisión de lectura de los catorce modelos contra las diecisiete migraciones,
  y de las llamadas entre clases contra las firmas reales de los servicios.

Lo que **no** se hizo, y está anotado con nombre y apellido: verificar las
versiones de las imágenes, probar el flujo de acceso, implementar la subida de
fotos, construir las imágenes, y correr un solo respaldo.

El detalle está en
[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md), que también lista
los **tres errores reales** que se encontraron en el código que ya existía, y
por qué se corrigieron o no.

---

## Cómo está armado

```
parqueadero/
├── docs/     01 arquitectura · 02 modelo de datos · 03 multitenancy y roles
│             04 instalación · 05 configuración · 06 ingreso de vehículo
│             07 seguridad · 08 respaldos · 09 API · 10 pruebas y estado
├── api/      Laravel 13 + PostgreSQL con Row Level Security
├── web/      Nuxt con render en el servidor
├── deploy/   Docker Compose, imágenes y scripts de respaldo
└── .env.example
```

**Laravel** expone la API y aplica las reglas de negocio.

**PostgreSQL** es la autoridad del aislamiento entre operadores y de la
inmutabilidad del registro. Es lo único que no se puede saltar por olvido.

**Nuxt** es la interfaz y el servidor intermedio: el navegador nunca habla
directo con la API.

**Redis** sostiene la cache de ocupación, las colas y el planificador.

**Authentik** resuelve la identidad: el estacionamiento no guarda contraseñas.

---

## Para empezar

```sh
cp .env.example .env          # complete los secretos
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d
```

Después, en este orden:

1. [docs/04-INSTALACION.md](docs/04-INSTALACION.md) — levantar y verificar.
2. [docs/03-MULTITENANCY-Y-ROLES.md](docs/03-MULTITENANCY-Y-ROLES.md) —
   entender el aislamiento **antes** de que entre nadie.
3. [docs/06-INGRESO-VEHICULO.md](docs/06-INGRESO-VEHICULO.md) — el recorrido
   desde el auto que entra hasta el reclamo.
4. [docs/08-RESPALDOS.md](docs/08-RESPALDOS.md) — respaldar y, sobre todo,
   **restaurar una vez**.
5. [docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md) — qué falta
   ejecutar.

Y lo primero de todo, antes de recibir un solo cliente:

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    exec api php artisan parqueadero:verificar-aislamiento
```

Tiene que salir con cuatro `[OK]`. Si no, el sistema no se usa.

El índice completo está en [docs/00-INDICE.md](docs/00-INDICE.md).