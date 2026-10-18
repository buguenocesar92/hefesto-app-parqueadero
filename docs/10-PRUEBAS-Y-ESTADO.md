# 10 - Pruebas y estado

Este es el documento más importante de los once. Está aquí para que nadie tenga
que preguntar qué funciona.

---

## 1. La respuesta corta

**No se ejecutó nada.**

En la máquina donde se escribió esta aplicación **no había PHP, ni Composer, ni
Node, ni Docker, ni PostgreSQL, ni Redis**.

En concreto:

| Cosa | Estado |
|---|---|
| Migraciones aplicadas | **Cero.** Ninguna de las diecisiete. |
| Pruebas de PHPUnit ejecutadas | **Cero.** Ninguna de las que están escritas. |
| Imágenes de Docker construidas | **Cero.** |
| Contenedores levantados | **Cero.** |
| Instancia de la API arrancada | **Cero.** |
| Interfaz construida o servida | **Cero.** |
| Comandos de artisan ejecutados | **Cero.** |
| Respaldo corrido | **Cero.** |
| Restauración probada | **Cero.** |

**Este sistema no se ha visto funcionando.** No hay captura de pantalla, no hay
salida de consola, no hay registro de ejecución. Cualquier persona que diga que
lo probó, se equivocó.

---

## 2. Lo que sí se validó

Cuatro cosas, y solo cuatro:

### 2.1 Estructura de archivos

El validador de Hefesto Studio (`tools/validar_apps.py`) recorre la aplicación y
comprueba:

```
[OK]    sintaxis YAML correcta en 2 archivo(s)
[OK]    deploy/api/docker/entrypoint.sh: sintaxis shell valida (bash -n)
[OK]    deploy/respaldos/respaldo.sh: sintaxis shell valida (bash -n)
[OK]    deploy/respaldos/restauracion.sh: sintaxis shell valida (bash -n)
[OK]    barrido de caracteres: limpio en N archivos
[OK]    barrido de secretos: sin valores reales
[OK]    Row Level Security presente en el codigo
[OK]    columna tenant_id en uso
[OK]    ningun puerto de base de datos o cache publicado en produccion
```

Eso comprueba que los archivos están donde deben y que tienen la forma
esperada. **No comprueba que el código funcione.**

### 2.2 Sintaxis YAML

Los dos archivos de `deploy/` parsean correctamente. Es sintaxis, no
semántica: un `depends_on` mal escrito parsea bien y hace lo que no debe.

### 2.3 Sintaxis de shell

Los tres scripts pasan `bash -n`. Eso significa que el shell los puede leer. No
significa que hagan lo correcto: `bash -n` no ejecuta nada.

### 2.4 Barrido de caracteres y de secretos

- **Caracteres**: sin chino, cirílico, árabe, hebreo, devanagari, tailandés,
  armenio ni coreano. Existe porque en otros proyectos de Hefesto se colaron
  repetidamente.
- **Secretos**: ningún valor real. Los `.env.example` tienen las claves vacías.

### 2.5 Revisión de lectura

Se leyó el código de principio a fin para comprobar que:

- Los nombres de columna de los **catorce modelos** existen en las migraciones.
- Las llamadas entre clases coinciden con las firmas reales de los servicios.
- Los nombres de las variables de entorno coinciden entre `.env.example`,
  `config/parqueadero.php` y los dos `docker-compose`.

Eso es una revisión humana, y **también se puede equivocar**. Es menos fuerte
que un compilador y más débil que una prueba.

---

## 3. Lo que hay escrito y no se ha corrido

### Las pruebas

Hay **cinco archivos de prueba** con pruebas en ellos:

| Suite | Archivo | Qué comprueba |
|---|---|---|
| Aislamiento | `AislamientoDeOperadoresTest` | Que un operador no vea ni toque los datos de otro |
| Aislamiento | `RegistroInmutableTest` | Que el registro no se pueda reescribir |
| Ocupación | `UnLugarUnaSesionTest` | Que un lugar tenga a lo sumo un vehículo adentro |
| Tarifas | `CalculoDeCobroTest` | Que el cobro salga bien |
| Unitarias | `CalculadoraTiempoTest` | El cálculo del tiempo |

**Ninguna se ejecutó.** Ni una.

### La configuración de pruebas

`phpunit.xml` fija `DB_CONNECTION=pgsql` **de forma explícita**, y no deja que
la herede del `.env`.

Esto no es un detalle menor. El aislamiento entre operadores está implementado
con Row Level Security, y la inmutabilidad del registro con un gatillo. En
SQLite **ninguna de las dos cosas existe**: las pruebas pasarían en verde sin
comprobar absolutamente nada. Lo mismo con el índice único parcial de
`estacionamientos`, que SQLite no tiene.

Es un error que ya se cometió en otro proyecto de Hefesto Studio, y por eso está
comentado dentro del propio `phpunit.xml`, para que nadie lo "simplifique"
sacando esa línea.

### Las tareas diferidas

`AvisarVencimientosMensualidadJob` y `CerrarJornadaJob` están escritos y no se
encolaron ni se ejecutaron nunca.

---

## 4. Lo que hay que ejecutar primero

En este orden. Los tres primeros son bloqueantes: si fallan, no se sigue.

### 4.1 La comprobación del aislamiento

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d

docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    exec api php artisan parqueadero:verificar-aislamiento
```

Tiene que salir con cuatro `[OK]`. Si no, **el sistema no se usa**.

### 4.2 Las migraciones

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    exec api php artisan migrate --force
```

Verificar que las diecisiete aplicaron y que el esquema quedó como dicen las
migraciones.

### 4.3 La suite de aislamiento

```sql
CREATE DATABASE parqueadero_test OWNER parqueadero_migra;
```

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    run --rm api php artisan test --testsuite=Aislamiento
```

Si estas pruebas pasan, el aislamiento por operador y la inmutabilidad del
registro funcionan. Si fallan, **el sistema no se usa**, y el motivo está en la
salida.

### 4.4 El resto de la suite

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    run --rm api php artisan test
```

### 4.5 El registro y las sesiones

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    exec api php artisan parqueadero:verificar-registro
```

### 4.6 El ejemplo

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    exec api php artisan db:seed
```

El seeder escribe los ingresos **con `RegistradorIngreso`**, no con un `insert`
directo. Si el seeder falla, es que hay un problema real en el servicio de
ingreso, y conviene enterarse antes de que lo descubra un operario.

### 4.7 El recorrido de punta a punta

El de [06](06-INGRESO-VEHICULO.md), sección 7. De punta a punta, con la
interfaz, como un operador.

### 4.8 Un respaldo y una restauración

```sh
./deploy/respaldos/respaldo.sh
./deploy/respaldos/restauracion.sh --simular /var/backups/parqueadero/parqueadero-AAAAMMDD-HHMMSS
./deploy/respaldos/restauracion.sh /var/backups/parqueadero/parqueadero-AAAAMMDD-HHMMSS
```

En una base de prueba. **Un respaldo que nunca se restauró no es un respaldo.**

---

## 5. Errores reales que se encontraron en el código que ya existía

Al revisar lo que venía escrito, se encontraron cuatro problemas. **Tres se
corrigieron y uno se documentó como decisión.**

### 5.1 Corregido: `scopeDelOperadorActual` comparaba contra el nombre

En `PerteneceAUnOperador`:

```php
// Antes: comparaba la columna contra el NOMBRE de la variable.
return $consulta->where(
    $this->getTable() . '.tenant_id',
    ContextoTenant::VARIABLE   // 'app.tenant_id'  ← una cadena, no un id
);
```

La consulta iba a ser `WHERE tenant_id = 'app.tenant_id'`, que no empareja
nada. **Cualquiera que usara ese scope recibía cero filas**, siempre.

Ahora lee el valor de la variable de sesión:

```php
$contexto = new ContextoTenant();

return $consulta->where(
    $this->getTable() . '.tenant_id',
    $contexto->tenantIdEnConexion()
);
```

Sin contexto, el valor es `null` y la consulta **no devuelve nada**, que es el
comportamiento correcto para una comodidad. La garantía real sigue siendo la
RLS, que además falla cerrada.

### 5.2 Corregido: el movimiento de salida leía una columna que no existe

En `RegistradorSalida`:

```php
// Antes:
'tipo_vehiculo' => $bloqueada->tipo_vehiculo ?? 'automobile',
```

**`estacionamientos` no tiene columna `tipo_vehiculo`.** La migración `000009`
no la crea. Eloquent devolvía `null`, así que **toda salida quedaba registrada
como `automobile`**, y el mismo servicio, seis líneas más arriba, leía
correctamente desde `$bloqueada->vehiculo?->tipo_vehiculo`.

El comentario de dos líneas más arriba lo decía: "el tipo de vehículo se lee
del vehículo, no de la sesión". El código no lo seguía.

Consecuencia real: en un registro, un caminhón y un auto son indistinguibles. Y
un reclamo que dice "estaba estacionada una camioneta blanca" no se puede
investigar.

Ahora:

```php
'tipo_vehiculo' => $bloqueada->vehiculo?->tipo_vehiculo?->value
    ?? TipoVehiculo::Automobile->value,
```

### 5.3 Corregido: el modelo `Tarifa` proponía tipos que el cálculo no acepta

El modelo `Tarifa` declaraba cuatro constantes: `por_hora`, `por_fraccion`,
`por_dia` y `mes`.

`CalculadoraTarifa::calcular()` acepta **dos**: `por_tiempo` y `por_evento`, y
lanza `RuntimeException` con cualquier otro:

```php
if ($tarifa->tipo !== 'por_tiempo') {
    throw new RuntimeException('Tipo de tarifa desconocido: ...');
}
```

Un operador que siguiera el modelo habría creado tarifas `por_hora` que **se
guardaban bien y fallaban al cobrar**, en la barrera, con el cliente esperando.

El modelo se ajustó a lo que el cálculo hace de verdad. No al revés.

### 5.4 Documentado, no corregido: la cortesía y el redondeo del operador

`configuraciones` tiene `minutos_cortesia` y `minutos_redondeo`. El cálculo del
cobro **no los lee**: usa `tarifas.minutos_incluidos` y
`tarifas.minutos_por_fraccion`.

También existe `CalculadoraTiempo::minutosFacturables()`, que **no llama
nadie**. Está escrita, con su documentación, y no se usa.

**Por qué no se corrigió:**

1. Conectarlas cambiaría el monto que cobran los estacionamientos que ya
   emitieron boletas. Cambiar un cobro en curso sin avisar es exactamente lo que
   este sistema evita.
2. La decisión es del operador: ¿cortesía por sede o por tarifa? Son dos modelos
   de negocio distintos, y ninguno es el correcto por omisión.
3. Borrar `minutosFacturables()` sería más limpio, pero es una decisión que
   corresponde a quien revise el código con el negocio a la vista.

Mientras tanto, `ConfiguracionController` devuelve
`minutos_cortesia_en_uso: false`, para que la interfaz no prometa algo que no
pasa.

---

## 6. Lo que quedó pendiente de verificar

Todo lo que sigue **no se comprobó**:

### 6.1 Versiones de paquetes e imágenes

Las versiones están fijadas por tag exacto, **no por digest**:

| Paquete | Versión declarada |
|---|---|
| `php` (imagen) | `8.4-fpm-alpine` |
| `node` (imagen) | `24.22.0-alpine3.24` |
| `postgres` (imagen) | `17.6-alpine` |
| `redis` (imagen) | `8.2.3-alpine` |
| `caddy` (imagen) | `2.10.0-alpine` |
| `laravel/framework` | `^13.37` |
| `nuxt` | `3.20.1` |

**Un tag puede cambiar de contenido sin que cambie el nombre.** No se comprobó
que ninguno de estos tags exista, ni qué versión corresponde a cada uno, porque
no había red ni registro de paquetes disponible.

Antes de producción, cada una debe fijarse por digest SHA-256:

```sh
docker pull php:8.4-fpm-alpine
docker inspect --format='{{index .RepoDigests 0}}' php:8.4-fpm-alpine
```

**Si alguna versión no existe, el build falla.** Eso es preferible a que funcione
con una versión que nadie verificó.

### 6.2 Las rutas

Las rutas están declaradas en `api/routes/web.php` y los controladores existen.
**No se compiló el enrutador** (`php artisan route:list`), así que:

- No se comprobó que no haya rutas duplicadas.
- No se comprobó que los nombres de las rutas coincidan con los que usan los
  controladores.
- No se comprobó que el patrón de `StoreLugarRequest` con
  `$this->route('lugar')` resuelva como se espera.

### 6.3 El flujo de acceso

El `AuthController` tiene la forma del flujo. **La redirección a Authentik, la
verificación del token y el enlace de la cuenta no se probaron**, porque no hay
una instancia de Authentik disponible.

Lo que sí se hizo: la configuración replica la de las otras aplicaciones de
Hefesto, y `VerificadorIdToken` comprueba firma, emisor y audiencia.

### 6.4 La carga de la foto

La columna `foto_ruta` existe y se guarda la ruta. **La subida del archivo no
está implementada** en ningún lado: no hay endpoint que reciba la imagen, ni
código que la mueva al volumen.

Por eso `PARQUEADERO_FOTO_OBLIGATORIA` está en `false` y lleva un aviso en tres
lugares. **Activar esa variable sin implementar la subida deja filas con una
ruta que no lleva a nada**, y el registro de ese vehículo no tiene la evidencia
que un reclamo asume que tiene.

### 6.5 El despliegue

Los archivos de `deploy/` están escritos y su sintaxis valida. **No se
construyó ninguna imagen, no se levantó ningún contenedor, no se obtuvo ningún
certificado.**

En particular, sin verificar:

- Que `composer.lock` exista o se genere. Los `Dockerfile` lo usan si está
  (`composer.lock*`), y si no, `composer install` lo resuelve, lo que instala
  versiones que nadie eligió.
- Que el volumen `volumen-fotos` quede accesible por el usuario de la
  aplicación.
- Que Caddy obtenga el certificado. Requiere el dominio apuntando al servidor y
  el 80 libre.

### 6.6 El respaldo y la restauración

Los dos scripts están escritos, y `bash -n` los valida. **No se corrieron ni
una vez**, ni en el camino feliz ni en un error.

Sin probar están, entre otros: la generación del `SHA-256`, la escritura del
manifiesto, la comprobación de espacio, el rechazo de destino ocupado, y el
`pg_restore --single-transaction`.

### 6.7 El rendimiento

No se midió nada. En particular, dos cosas que podrían no andar bien con
volumen real y que conviene mirar temprano:

1. **El historial de movimientos** crece sin límite. Con diez años de operación
   son millones de filas. Los índices están puestos, pero el comportamiento con
   volumen no se probó.

2. **`CalculadoraOcupacion::estado()`** recalcula con `COUNT(*)` sobre sesiones
   abiertas. Hoy está bien porque el índice parcial lo hace barato. Si el
   indicar se pierde por alguna razón, el conteo se vuelve un recorrido de
   tabla.

### 6.8 La corrección del código encontrado

Las correcciones de la sección 5 se hicieron **por lectura**, no con una prueba
que fallara primero y pasara después. Es la diferencia entre "encontré el error"
y "demostré que el error estaba y que ya no está".

**Hay que escribir pruebas que cubran los tres casos corregidos** cuando haya un
entorno:

1. `scopeDelOperadorActual()` devuelve filas y no cero filas.
2. El movimiento de salida registra el tipo de vehículo real.
3. Una tarifa con tipo no soportado se rechaza al guardarla.

---

## 7. Riesgos que siguen abiertos

Con todas las letras, sin adornos:

1. **No hay revisión de seguridad externa** ni pruebas de penetración.
2. **No hay política de retención de datos**, ni para fotos, ni para reclamos, ni
   para los datos del titular. Ver [07](07-SEGURIDAD.md), sección 1.
3. **No hay exportación de datos del titular**, que es lo que se pide cuando un
   cliente exercising su derecho.
4. **No hay cifrado en reposo** de la base ni de las fotos.
5. **No hay un segundo nivel de autenticación** forzado. Depende de Authentik.
6. **Las imágenes están fijadas por tag, no por digest.**
7. **Hay una duplicación de código conocida**: la búsqueda de tarifa vigente está
   escrita dos veces, en `RegistradorSalida::tarifaVigente()` (privada) y en
   `ReconstructorDeBoleta::tarifaVigente()`. Se dejó así para no modificar un
   servicio que ya venía escrito y usado. Cuando una de las dos cambie y la otra
   no, una boleta reconstruida va a salir con un precio distinto al original, que
   es exactamente lo que la reconstrucción promete evitar. **Es la deuda más
   concreta que queda en el código.**
8. **No se probó la concurrencia real** de dos operarios marcando el mismo lugar
   al mismo tiempo. El índice único parcial lo garantiza, pero no se ejecutó.
9. **No hay alerta automática** si el respaldo deja de correr. Un respaldo que
   deja de crecer es un respaldo que dejó de existir, y se descubre el día que
   hace falta.

---

## 8. Resumen

| | |
|---|---|
| Archivos escritos | Completos para la forma de las otras aplicaciones de Hefesto |
| Pruebas escritas | 5 archivos |
| Pruebas ejecutadas | **0** |
| Migraciones aplicadas | **0** |
| Imágenes construidas | **0** |
| Bugs reales encontrados y corregidos | 3 |
| Decisiones documentadas sin corregir | 1 |
| Versiones verificadas | **0** |

La conclusión honesta es esta: **el código está escrito con cuidado y con un
contrato de datos coherente, y no se ha comprobado que funcione.** El trabajo
de aquí en adelante es ejecutarlo, empezar por la comprobación del aislamiento,
que es la que decide si el sistema puede usarse con clientes o no.