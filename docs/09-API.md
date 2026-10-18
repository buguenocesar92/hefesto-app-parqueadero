# 09 - API

Todos los endpoints. Prefijo: `/api/v1`.

> **Ninguno de estos endpoints se ejecutó.** No había PHP, ni Composer, ni
> PostgreSQL en el equipo donde se escribió. Lo que hay son las rutas
> declaradas y los controladores escritos. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Convenciones

### Prefijo y versión

Todo bajo `/api/v1`. Un cambio incompatible es un `/api/v2`, no romper el
`v1`.

### El aislamiento no está en las rutas

Cada ruta cuelga del grupo `web`, donde `ResolverTenant` envuelve la petición en
una transacción con el operador fijado y la política RLS filtra lo que se lee y
lo que se escribe.

**No hay ningún `middleware('tenant:...')` que se pueda olvidar** en una ruta
nueva. La garantía no depende de acordarse de escribirlo.

### 404, no 403

Cuando una Policy rechaza, la respuesta es 404 con `{"mensaje": "No encontrado."}`.
Un 403 confirmaría que el recurso existe en otro operador.

Con el enlace implícito de Laravel, si el id pertenece a otro operador, el
modelo no aparece y sale 404 sin código extra.

### Errores de dominio

Vienen con código estable, para que la interfaz reaccione sin adivinar:

```json
{ "mensaje": "Este lugar ya tiene un vehículo adentro.", "codigo": "lugar_con_vehiculo" }
```

| Código | HTTP | Qué pasó |
|---|---|---|
| `ingreso_rechazado` | 422 | El ingreso no se pudo registrar. El mensaje viene del servicio. |
| `salida_rechazada` | 422 | La salida no se pudo cobrar. |
| `reconstruccion_rechazada` | 422 | No se pudo reemitir la boleta. |
| `lugar_con_vehiculo` | 422 | No se desactivó: hay un vehículo adentro. |
| `boleta_ya_anulada` | 422 | La boleta ya estaba anulada. |
| `vehiculo_con_mensualidad` | 422 | El vehículo ya tiene una mensualidad activa. |
| `tarifa_incompleta` | 422 | Una tarifa por tiempo sin minutos por fracción. |
| `transicion_no_permitida` | 422 | Salto de estado inválido en un reclamo. |
| `regla_de_parqueadero` | 422 | Error de regla genérico. |
| `sin_contexto_operador` | 422 | La consulta se ejecutó sin operador. Es un fallo de programación. |
| `busqueda_sin_criterio` | 422 | Se pidió contexto de reclamo sin patente ni VIN. |

---

## 2. Salud

### `GET /api/v1/salud`

**Pública, sin sesión.** La corre el orquestador. No dice nada de los datos:
ni cuántos operadores hay, ni cuántos lugares, ni cuánto se cobró. Solo si las
piezas de abajo contestan.

```json
{
  "estado": "ok",
  "componentes": {
    "base": { "ok": true, "detalle": "Base accesible con el aislamiento aplicado." },
    "cache": { "ok": true, "detalle": "Cache y colas operativas." }
  },
  "momento": "2026-10-17T12:00:00+03:00"
}
```

Devuelve **503** si algo no está bien. `estado` es `degradado` cuando la base
responde pero la función de contexto no existe: un servicio de pie sin
aislamiento es peor que uno caído.

---

## 3. Sesión

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/yo` | Quién es, con lo que la interfaz necesita para armar el menú. |
| `POST` | `/salir` | Cierra la sesión y marca `ultimo_ingreso`. |
| `GET` | `/sesion` | Sesión viva y base accesible. |
| `GET` | `/contexto` | Diagnóstico del contexto de operador. |
| `GET` | `/cuentas` | Las personas del operador. Solo administración. |
| `GET` | `/acceso` | Redirección a Authentik. |
| `GET` | `/acceso/callback` | Vuelve del proveedor de identidad. |

`/yo` devuelve `rol`, `rol_etiqueta` y tres banderas (`puede_operar`,
`puede_cobrar`, `puede_administrar`). La interfaz las usa para esconder botones.

**Eso no es autorización.** La autorización la hace cada endpoint, contra la
Policy correspondiente. El menú es una cortesía.

### `GET /contexto`

Responde una pregunta muy concreta de soporte: "¿esta petición está viendo los
datos del operador correcto?".

```json
{
  "operador_solicitado": "…uuid…",
  "operador_en_conexion": "…uuid…",
  "coinciden": true,
  "en_transaccion": true,
  "nombre": "Estacionamiento de prueba Vicuña Mackenna"
}
```

Si `coinciden` es `false`, hay un problema de aislamiento. Si `en_transaccion`
es `false`, el contexto quedó puesto con alcance de sesión, que es exactamente
el fallo que el diseño evita.

---

## 4. Sedes, panel y ocupación

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/sedes` | Las sedes del operador. |
| `GET` | `/sedes/{id}` | Una sede. |
| `GET` | `/panel` | Resumen de todas las sedes. |
| `GET` | `/sedes/{id}/panel` | El panel de una sede. |
| `GET` | `/sedes/{id}/ocupacion` | La fotografía de ocupación. |
| `GET` | `/sedes/{id}/ocupacion/lugares` | Los lugares, con quién los tiene. |

### `GET /sedes/{id}/ocupacion`

```json
{
  "capacidad": 20,
  "ocupados": 2,
  "disponibles": 18,
  "sobrecapacidad": false,
  "lugares_activos": 20,
  "lugares_inactivos": 1,
  "capacidad_declarada": 24,
  "capacidad_real": 20,
  "coincide": false
}
```

`capacidad` es la **cuenta de lugares activos**. `capacidad_declarada` es lo que
el operador escribió en `parqueaderos.capacidad_total`.

Cuando `coincide` es `false`, la interfaz avisa. Un sistema que elige una de las
dos y esconde la diferencia termina prometiendo un puesto que no existe.

---

## 5. Lugares

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/sedes/{id}/lugares` | Los lugares de la sede. |
| `POST` | `/sedes/{id}/lugares` | Crear un lugar. |
| `PUT` | `/lugares/{id}` | Cambiar tipo o reserva. **El código no se cambia.** |
| `DELETE` | `/lugares/{id}` | Desactivar. No borra. |

### Por qué no se edita el código

El código es lo que está pintado en el suelo y lo que el operario escribe con
el dedo. Un lugar cuyo código cambia es un lugar que deja de existir para todos
los que ya lo memorizaron.

Para corregirlo: se desactiva el lugar y se crea otro con el código correcto.
El `StoreLugarRequest` devuelve 422 con `codigo` prohibido si se intenta cambiar
el código.

### Desactivar

Se rechaza con `lugar_con_vehiculo` si hay una sesión abierta. Sin vehiculos
dentro, se marca `activo = false`.

---

## 6. Tarifas

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/sedes/{id}/tarifas` | Todas, incluidas las terminadas. |
| `GET` | `/sedes/{id}/tarifas/vigentes` | Solo las que rigen ahora. |
| `POST` | `/sedes/{id}/tarifas` | Crear una tarifa con vigencia. |
| `PUT` | `/tarifas/{id}` | Editar. Solo si la vigencia empieza en el futuro. |
| `DELETE` | `/tarifas/{id}` | Desactivar y cerrar vigencia. |

**Solo dos tipos:** `por_tiempo` y `por_evento`. Cualquier otro valor se
rechaza en validación, porque el cálculo no lo entendería y el cobro fallaría en
la barrera.

Una tarifa por tiempo **requiere** `minutos_por_fraccion` mayor que cero. La
validación da el mensaje en el formulario, no cuando el cliente está esperando
el cobro.

`editable` viene en la respuesta: `true` solo si `vigente_desde` está en el
futuro. Ahí todavía no cobró nadie, así que corregir un error de tipeo no
reescribe nada.

---

## 7. Ingreso

### `POST /api/v1/ingresos/revisar`

**No registra nada.** Solo informa. Se llama mientras el operador escribe la
patente.

```json
{
  "estado": "vigente",
  "permite_paso": true,
  "bloquea": false,
  "mensualidad": { "cliente_nombre": "…", "credencial_vence_en": "2026-11-06" },
  "avisos": ["Mensualista al día. Credencial vigente hasta el 06-11-2026."]
}
```

Estados posibles: `visitante`, `vigente`, `credencial_vencida`, `pago_atrasado`,
`contrato_terminado`.

**`bloquea` no cierra ninguna puerta.** Es un aviso. La barrera la abre una
persona.

### `POST /api/v1/ingresos`

| Campo | Regla |
|---|---|
| `patente` | Obligatoria. 4 a 12 caracteres, letras, números, punto y guion. |
| `vin` | Obligatorio. **17 caracteres, sin I, O ni Q.** |
| `tipo_vehiculo` | Obligatorio. |
| `lugar_id` | Obligatorio. El sistema **no** elige el lugar. |
| `foto_ruta`, `color`, `marca`, `modelo` | Opcionales. |

Devuelve `201` con la sesión, el código del lugar, la referencia del ticket y la
ocupación actualizada.

Cuando el lugar se tomó entre tiempo: **422** con `codigo`
`ingreso_rechazado` y un mensaje que nombra el lugar libre más cercano.

---

## 8. Salida

### `POST /api/v1/salidas/buscar`

Ubica la sesión abierta **sin cobrar**. Los tres criterios: `estacionamiento_id`,
o `patente`, o `vin`. Con más de una coincidencia, devuelve la primera y
`candidatas` con el total; el operario decide.

Devuelve el tiempo transcurrido y las tarifas vigentes de la sede. **No devuelve
el monto**: la tarifa se busca por la fecha del ingreso, y calcularla acá
mostraría una cifra que podría no ser la que sale. En una pantalla de cobro,
mostrar el número equivocado es peor que no mostrar nada.

### `POST /api/v1/salidas`

| Campo | Regla |
|---|---|
| `estacionamiento_id` | Obligatorio si no se manda patente ni VIN. |
| `patente` / `vin` | Obligatorios si no se manda el id. |
| `forma_pago` | Obligatoria: `efectivo`, `tarjeta`, `transferencia`. |
| `descuento` | Entero, 0 o más. No puede pasar del total. |
| `salida_en` | Opcional. Solo para corregir un registro con la hora equivocada. |

Si no hay ninguna sesión abierta con esa identificación: **404**.

Si ya se cobró: **422** con `salida_rechazada`. **Nunca se cobra dos veces el
mismo estacionamiento.**

También existe `POST /estacionamientos/{id}/salida`, que es la misma operación
con el id en la ruta.

### `POST /estacionamientos/{id}/reconstruir`

Reemite la boleta de una sesión ya cerrada. El caso real: el cliente perdió el
ticket.

Rechaza con 422 si el vehículo **sigue adentro** (`sesion_abierta`) o si
**ya tiene boleta** (`ya_tiene_boleta`). En el segundo caso lo que corresponde es
reimprimir la existente.

---

## 9. Registro

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/sedes/{id}/movimientos` | Historial por ventana de fechas. |
| `GET` | `/estacionamientos/{id}/movimientos` | Todo lo que le pasó a un vehículo en esa visita. |
| `POST` | `/movimientos/{id}/anotaciones` | Agregar una aclaración. |

### No hay `PUT` ni `DELETE` de movimientos

No existen, y no es una omisión: la migración `000017` pone un gatillo que
rechaza `UPDATE` y `DELETE` sobre la tabla. Un `psql` con la contraseña tampoco
puede reescribir el registro.

### Anotar

La **única** operación de escritura sobre el registro. No toca la fila del
movimiento: escribe en `anotaciones_movimiento`, que la migración deja abierta a
propósito.

Ejemplo de lo que se escribe ahí: que la cámara estaba sin contacto, o que el
vehículo estaba estacionado con las luces apagadas y se confirmó que no era el
del cliente. Esa frase es la que sostiene el reclamo después.

### El historial tiene tope

`por_pagina` por omisión 100, máximo 200. Un historial sin tope devuelve la
historia entera del operador, que en un estacionamiento con diez años son
millones de filas.

---

## 10. Boletas

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/sedes/{id}/boletas` | Las del día, con su total. |
| `GET` | `/sedes/{id}/boletas/total` | Solo el total del día. |
| `GET` | `/boletas/{id}` | Una boleta. |
| `GET` | `/boletas/{id}/detalle` | El desglose completo del cálculo. |
| `POST` | `/boletas/{id}/anular` | Anular con motivo. |

### Emitir NO está acá

No hay `POST /boletas`. La boleta se emite **dentro de la salida**, en la misma
transacción que cierra el estacionamiento. Si la boleta y la salida pudieran
quedar separadas, un corte de luz entre las dos deja un vehículo afuera del
registro con un cobro hecho.

### Anular

Solo administración. Exige `motivo` de 10 caracteres o más.

**No borra y no cambia el total.** Pasa a `anulada` con el motivo, y la fila
sigue ahí con la cifra que se cobró. Es lo que permite que el total del día
cuadre y que la anulación sea auditable.

Se anota en bitácora con el folio y el total.

---

## 11. Mensualidades

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/sedes/{id}/mensualidades` | El listado. **Sin RUT, correo ni teléfono.** |
| `GET` | `/mensualidades/por-cobrar` | Vencidas y próximas a vencer. |
| `GET` | `/mensualidades/{id}` | Ficha. El contacto sale solo con `verContacto`. |
| `POST` | `/sedes/{id}/mensualidades` | Crear. |
| `POST` | `/mensualidades/{id}/pagos` | Registrar un pago. |
| `PUT` | `/mensualidades/{id}/estado` | Suspender, reactivar o terminar. |

### El listado no trae el dato de contacto

Es una decisión del **servidor**, no de la pantalla:
`MensualidadController::presentar($mensualidad, conContacto: false)`.

Si fuera de la interfaz, bastaría abrir las herramientas de desarrollo para ver
el RUT de todos los clientes.

### Registrar un pago

| Campo | Default | Qué hace |
|---|---|---|
| `meses` | `1` | Cuántos meses se pagaron. |
| `acumular` | `false` | Con `true`, el periodo arranca cuando termina el anterior. |
| `monto` | — | Para la bitácora. |

Renueva la credencial, y **levanta una suspensión**.

**No termina nada.** Ninguna mensualidad vence sola porque pasó la fecha.

---

## 12. Reclamos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/sedes/{id}/reclamos` | El listado. **Sin nombre, RUT, relato ni foto.** |
| `GET` | `/reclamos/contexto` | Qué pasó con esa patente en esa ventana. |
| `GET` | `/reclamos/{id}` | Ficha. Completa solo con `verFichaCompleta`. |
| `POST` | `/sedes/{id}/reclamos` | Registrar. |
| `PUT` | `/reclamos/{id}/estado` | Mover de estado. |

### `GET /reclamos/contexto`

Es la pantalla de escritorio del reclamo, y la que más se usa.

| Campo | Regla |
|---|---|
| `patente` o `vin` | Alguno de los dos es obligatorio. |
| `desde`, `hasta` | Obligatorios, en ese orden. |

Se llama **antes** de escribir el reclamo, mientras el cliente sigue en la
ventanilla: es el momento en que todavía se le puede preguntar algo.

### Registrar

| Campo | Regla |
|---|---|
| `fecha_incidente` | **Obligatoria.** Sin ella no hay nada que buscar. |
| `hora_incidente` | Opcional. El cliente recuerda "el martes" mejor que un instante. |
| `descripcion` | Obligatoria, 10 caracteres o más. **No se resume ni se corrige.** |
| `patente_vista` | Lo que dijo el cliente. Puede no coincidir con la base: esa diferencia es la pista. |
| `cliente_rut`, `cliente_contacto` | Opcionales. Son dato personal. |
| `foto_ruta` | Opcional, pero la columna es `NOT NULL`: sin foto va la cadena vacía. |

El folio lo genera el sistema: `R-000001`. Es dictable por teléfono, que el
UUID interno no lo es.

### Mover de estado

Transiciones válidas: `abierto` → `en_revision` → `resuelto` o `rechazado`.

Un salto inválido devuelve **422** con `transicion_no_permitida`. La validación
está en `EstadoReclamo::puedeIrDesde()`, que es donde está el mapa completo.

**Cerrar exige `resolucion` escrita.** La validación está en el formulario, y el
cambio de estado se anota en bitácora.

---

## 13. Vehículos y configuración

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/vehiculos` | El padrón. Busca por `q` en patente, VIN, marca o modelo. |
| `GET` | `/vehiculos/{id}` | Un vehículo y si tiene mensualidad. |
| `GET` | `/configuracion` | La configuración del operador. |
| `PUT` | `/configuracion` | Cambiarla. Solo administración. |

### No hay `POST /vehiculos`

El padrón **se llena solo**: `RegistradorIngreso` crea la fila la primera vez
que el auto entra. Un padrón escrito a mano se desactualiza solo: en dos meses
la mitad de los autos ya no están.

`GET /vehiculos` tiene tope de 200 filas.

### La configuración

`GET` devuelve además `minutos_cortesia_en_uso: false`, que hoy siempre es
`false`: el cobro no lee esos valores, usa los de cada tarifa. Está declarado
para que la interfaz no prometa algo que no pasa.

`PUT` se anota en bitácora con el valor anterior y el nuevo.

---

## 14. Comandos de consola

No son endpoints, pero son parte de la operación.

| Comando | Qué hace |
|---|---|
| `parqueadero:verificar-aislamiento` | Cuatro comprobaciones. Sale con código distinto de cero si algo falla. |
| `parqueadero:verificar-registro` | Revisa que cada sesión tenga su movimiento. **Solo lee y reporta: no corrige nada.** |
| `parqueadero:cerrar-jornada` | Cierra el día de todas las sedes. `--directo` para instalaciones sin worker. |
| `parqueadero:avisar-mensualidades` | Deja en bitácora las vencidas y próximas a vencer. |
| `parqueadero:instalar` | Muestra las tareas propias del sistema. |
| `parqueadero:doc` | Dice dónde está la documentación. |

`parqueadero:verificar-aislamiento` y `parqueadero:verificar-registro` son los
dos que hay que correr **siempre** antes de dar por bueno un despliegue. Ver
[04](04-INSTALACION.md).