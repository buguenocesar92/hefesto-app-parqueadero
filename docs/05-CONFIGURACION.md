# 05 - Configuración

Todas las variables, qué hacen y qué pasa si están mal.

> Los archivos `.env.example` **no tienen ningún valor real**. Se copian a
> `.env` y ahí se completan. El `.env` real nunca se versiona.

---

## 1. El error que hay que evitar

Con `APP_DEBUG=true` en producción, cualquier error muestra rutas internas,
consultas SQL y a veces valores de variables a quien esté mirando la pantalla.

Con `APP_DEBUG=false`, el visitante ve un error genérico y el detalle queda en
el log. Es menos útil para el cliente y mucho más seguro.

**En producción, `APP_DEBUG` es `false` siempre.** Y es `true` en desarrollo,
que es para lo que existe.

---

## 2. Aplicación

| Variable | Default | Qué hace |
|---|---|---|
| `APP_NAME` | `Parqueadero` | Nombre de la aplicación. |
| `APP_ENV` | `local` / `production` | Entorno de Laravel. |
| `APP_KEY` | — | **Obligatoria.** Firma la cookie de sesión. Sin ella no hay sesión. |
| `APP_DEBUG` | `true` / `false` | Ver arriba. |
| `APP_URL` | — | URL de la API. |
| `APP_WEB_URL` | — | A dónde vuelve el operador después de entrar. Lo sirve Nuxt. |
| `LOG_LEVEL` | `debug` / `info` | En producción, `info`. |

Generar `APP_KEY`:

```sh
openssl rand -base64 32
```

---

## 3. Base de datos

| Variable | Default | Qué hace |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | **No cambiar.** El aislamiento depende de RLS. |
| `DB_HOST` | `postgres` | Nombre del servicio en Docker. |
| `DB_PORT` | `5432` | |
| `DB_DATABASE` | `parqueadero` | |
| `DB_USERNAME` | `parqueadero_app` | Rol de la aplicación. |
| `DB_PASSWORD` | — | |
| `DB_MIGRATE_USERNAME` | `parqueadero_migra` | Rol de migración. |
| `DB_MIGRATE_PASSWORD` | — | |

### Las dos reglas del rol de la aplicación

1. **No puede ser superusuario.**
2. **No puede tener `BYPASSRLS`.**

Con cualquiera de las dos, la Row Level Security es decorativa. No es una
recomendación: `parqueadero:verificar-aislamiento` lo comprueba en cada arranque
y el contenedor no levanta si está mal.

```sql
-- Cómo se comprueba a mano:
SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user;
```

Las dos columnas tienen que estar en `false`.

### Por qué dos roles

El rol de migración puede ser el dueño de la base, porque necesita crear
tablas, índices, políticas y gatillos. El rol de la aplicación no: solo
necesita `SELECT`, `INSERT`, `UPDATE` y `DELETE` sobre las tablas del sistema.

Con un solo rol, habría que darle el dueño de la base a la aplicación, que es
lo que hace que la RLS se desactive en la práctica aunque `FORCE ROW LEVEL
SECURITY` esté puesto.

---

## 4. Redis

| Variable | Default | Qué hace |
|---|---|---|
| `REDIS_HOST` | `redis` | |
| `REDIS_PASSWORD` | vacío | Ponerlo en producción: sin él, cualquier contenedor de la red puede leer la sesión. |
| `REDIS_PORT` | `6379` | |

Redis sostiene la cache de ocupación, las colas y la sesión. **Si Redis cae,
el sistema sigue operando**: la ocupación se recalcula contra PostgreSQL en
cada consulta. Es degradado, no caído. Lo reporta así `SaludController`.

---

## 5. Sesión

| Variable | Default | Qué hace |
|---|---|---|
| `SESSION_DRIVER` | `database` | Ver abajo. |
| `SESSION_LIFETIME` | `480` | Minutos. Ocho horas, que es un turno. |
| `SESSION_COOKIE` | `parqueadero_sesion` | Nombre propio, para no chocar con otra app de Hefesto en el mismo dominio. |
| `SESSION_SECURE_COOKIE` | `false` / `true` | **En producción, `true`.** Con `false`, la cookie viaja en claro. |
| `SESSION_SAME_SITE` | `lax` | |

**Por qué `database` y no `file`:** permite revocar una sesión desde la base si
alguien pierde el equipo de la caseta. En un estacionamiento eso pasa, y con
`file` la única forma de cortar el acceso es esperar a que la cookie caduque.

---

## 6. Identidad (Authentik)

| Variable | Default | Qué hace |
|---|---|---|
| `AUTHENTIK_ISSUER` | — | **Obligatoria.** Debe terminar en barra. |
| `AUTHENTIK_CLIENT_ID` | — | **Obligatoria.** |
| `AUTHENTIK_CLIENT_SECRET` | — | **Obligatoria.** |
| `AUTHENTIK_REDIRECT_URI` | — | **Obligatoria.** Debe coincidir con la registrada en Authentik. |
| `AUTHENTIK_SCOPES` | `openid profile email` | |
| `AUTHENTIK_ALGORITMO` | `RS256` | |
| `AUTHENTIK_GRUPO_ADMIN` | `parqueadero-administradores` | |
| `AUTHENTIK_GRUPO_OPERADORES` | `parqueadero-operadores` | |

### La barra del emisor

`https://id.ejemplo.cl/application/o/parqueadero/` y no
`https://id.ejemplo.cl/application/o/parqueadero`. Authentik pública el emisor
con barra final, y `VerificadorIdToken` compara sin la barra para tolerar
configuraciones equivalentes. Una barra de menos hace que **todos** los tokens
parezcan de otro emisor y que nadie pueda entrar.

### Los grupos NO dan permisos

El rol con el que se autoriza cada acción vive en `usuarios.rol`, que es del
parqueadero. Los grupos de Authentik sirven para que el alta de personas sea
auditable en el proveedor de identidad, y para mostrar la ayuda de
administración.

Si el grupo de Authentik dice administrador pero el rol en la base es
`lectura`, la persona es de lectura. La base manda.

---

## 7. Colas y planificador

| Variable | Default | Qué hace |
|---|---|---|
| `QUEUE_CONNECTION` | `database` | Las tareas diferidas van a una tabla, no a Redis. |
| `SCHEDULE_RUNNER` | `false` | Si se deja en `false`, no corre nada solo. |
| `CACHE_STORE` | `redis` | |

`QUEUE_CONNECTION=database` evita depender de Redis para la cola. Con `redis`,
las tareas diferidas se pierden si Redis no levanta, y un aviso de vencimiento
que se pierde es un cliente al que se le cobró de más.

---

## 8. Los parámetros del parqueadero

Estos están en `config/parqueadero.php`. Todos tienen valor por omisión
**explícito en el código**, no inventado.

| Variable | Default | Qué hace |
|---|---|---|
| `PARQUEADERO_MINUTOS_CORTESIA` | `10` | **Hoy no participa del cobro.** Ver la nota de abajo. |
| `PARQUEADERO_MINUTOS_REDONDEO` | `1` | **Hoy no participa del cobro.** |
| `PARQUEADERO_DIAS_AVISO_VENCIMIENTO` | `15` | Cuántos días antes aparece un mensualista en el aviso. |
| `PARQUEADERO_OCUPACION_TTL` | `30` | Segundos que vive una lectura cacheada del conteo. |
| `PARQUEADERO_ZONA_HORARIA` | `America/Santiago` | Cómo se muestra la hora. |
| `PARQUEADERO_NORMALIZAR_PATENTE` | `true` | Limpia puntos, espacios y guiones; deja en mayúsculas. |
| `PARQUEADERO_FORMA_PAGO` | `efectivo` | Forma de pago por omisión. |
| `PARQUEADERO_DESCUENTO_MAXIMO` | `5000` | Techo de aviso. El límite duro está en el código. |
| `PARQUEADERO_HISTORIAL_POR_PAGINA` | `100` | |
| `PARQUEADERO_HISTORIAL_MAXIMO` | `200` | Tope absoluto del historial. |
| `PARQUEADERO_FOTO_OBLIGATORIA` | `false` | Ver la nota de abajo. |
| `PARQUEADERO_FOTO_RUTA_BASE` | `fotos` | |
| `PARQUEADERO_VERIFICAR_AISLAMIENTO` | `true` | Que el entrypoint verifique la RLS antes de arrancar. |

### La nota sobre la cortesía y el redondeo

`PARQUEADERO_MINUTOS_CORTESIA` y `PARQUEADERO_MINUTOS_REDONDEO` **no participan
del cálculo del cobro de hoy**. El cálculo usa `tarifas.minutos_incluidos` y
`tarifas.minutos_por_fraccion`, que son por tarifa.

Es una discrepancia real, detectada al revisar. No se corrigió, y por qué está
en [02](02-MODELO-DE-DATOS.md) y en [10](10-PRUEBAS-Y-ESTADO.md): conectarlas
cambiaría el monto que cobran los estacionamientos que ya emitieron boletas, y
la decisión de si la cortesía es por sede o por tarifa es del operador.

### La nota sobre las fotos

`PARQUEADERO_FOTO_OBLIGATORIA` **no se debe activar sin montar el volumen de
fotos primero**. La foto se guarda como ruta en la base y el archivo lo sube la
interfaz. Si se exige la foto y el archivo no está, la fila queda con una ruta
que no lleva a nada, y el registro de ese vehículo no tiene la evidencia que el
caso de un reclamo asume que tiene.

El volumen es `volumen-fotos`, montado en
`/var/www/html/storage/app/fotos`.

---

## 9. La interfaz

En `web/.env`:

| Variable | Default | Qué hace |
|---|---|---|
| `NUXT_API_BASE` | `http://localhost:8000` | **Config privada.** Solo la usa el servidor de Nuxt. |
| `NUXT_PUBLIC_BASE` | `http://localhost:3000` | Config pública. |

`NUXT_API_BASE` nunca se expone como `NUXT_PUBLIC_*`, y por esa razón la URL
interna de la API no llega al navegador.

---

## 10. TLS

| Variable | Default | Qué hace |
|---|---|---|
| `ACME_CORREO` | — | Correo real para los avisos de caducidad del certificado. |

Sin esta variable, Caddy avisa por consola. Nadie ve la consola. El certificado
se vence y, en un sistema que maneja datos de clientes, eso es un incidente.

---

## 11. La lista de verificación de configuración

Antes de abrir al público:

- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` generada y no la de por omisión
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `REDIS_PASSWORD` puesta
- [ ] `AUTHENTIK_ISSUER` con barra al final
- [ ] `AUTHENTIK_REDIRECT_URI` coincide con lo registrado en Authentik
- [ ] El rol de la aplicación **no** es superusuario ni tiene `BYPASSRLS`
- [ ] `ACME_CORREO` es un correo real
- [ ] `DIRECCION_RESGARDOS` apunta a un directorio que existe
- [ ] Hay **tarifas vigentes**. Sin tarifas no se puede cobrar ninguna salida.
- [ ] Hay **lugares cargados**
- [ ] `PARQUEADERO_FOTO_OBLIGATORIA` sigue en `false` salvo que el volumen de
      fotos esté montado y probado