# 06 - Ingreso de vehículo

El recorrido completo de un auto que entra, de uno que sale y de uno que
reclama. Es el documento que hay que leer para entender el sistema en una
sentada.

---

## 1. Advertencia antes que nada

**Este sistema no cierra ninguna puerta.**

Una mensualidad vencida produce un **aviso**. La barrera la abre una persona.
Un sistema que cerrara el portón por una regla dejaría al operador sin poder
abrir en una emergencia, y esa decisión no le corresponde a un programa.

La configuración se llama `mensualidad_vencida_bloquea_acceso`, y el nombre
promete más de lo que hace: **marca y avisa**, nunca impide. Está escrito así en
el nombre del campo, en el código y en la documentación, porque es más fácil
corregir un nombre que una sorpresa en la caseta a las tres de la mañana.

---

## 2. Entra un vehículo

### 2.1 Lo que ve el operador

La pantalla de ingreso muestra:

1. La lista de **lugares libres**, con su código y su tipo.
2. Un formulario: patente, VIN, tipo de vehículo, lugar, y opcionalmente marca,
   modelo y color.
3. Un aviso que aparece cuando escribe el VIN.

La lista de lugares libres viene de las **sesiones abiertas**, no de un campo
`ocupado`. Es lo único que puede ser cierto sin depender de que todos
escriban bien.

### 2.2 La revisión de acceso

Cuando el operador escribe el VIN y sale del campo, el sistema llama a
`POST /api/v1/ingresos/revisar`. No registra nada: solo informa.

`VerificadorDeAcceso` devuelve un estado y una lista de avisos:

| Estado | Qué significa | Aviso al operador |
|---|---|---|
| `visitante` | No hay mensualidad | Ninguno |
| `vigente` | Contrato vivo, credencial al día, pagado | "Mensualista al día" |
| `credencial_vencida` | **Pagó y quedó sin permiso** | "Pagó, pero la credencial venció el día X" |
| `pago_atrasado` | Contrato vivo, sin pagar | "Sin pago desde el día X" |
| `contrato_terminado` | Se acabó el acuerdo | "El contrato terminó" |

### 2.3 Por qué son tres fechas separadas

Porque **mezclarlas en una columna hace que un cliente que pagó pero quedó sin
credencial se vea igual que uno que no pagó**, y el operador termine cobrando a
quien ya no tiene contrato, o dando acceso a quien no pagó.

| Fecha | Pregunta que responde |
|---|---|
| `fecha_inicio` / `fecha_termino` | ¿Existe el acuerdo comercial? |
| `fecha_ultimo_pago` | ¿Está al día con la plata? |
| `credencial_vence_en` | ¿Tiene permiso para pasar? |

Las tres están separadas en el modelo, en la pantalla y en la base. Ver
[02](02-MODELO-DE-DATOS.md).

### 2.4 Lo que ocurre al marcar

`RegistradorIngreso::registrar()` hace, en **una sola transacción**:

1. Normaliza la patente (mayúsculas, sin puntos ni guiones) y el VIN.
2. Busca el lugar. **Fuera** de la transacción, a propósito: el manejador de
   colisiones necesita saber a qué sede pertenece el intento para poder
   ofrecer un lugar libre concreto.
3. Crea el vehículo si no existe. **El padrón se llena solo**, la primera vez
   que entra un auto.
4. Crea la sesión en `estacionamientos`, con `ingreso_en` del reloj del
   servidor.
5. Crea el movimiento de tipo `ingreso`, con **copias literales** de patente,
   VIN, tipo y código de lugar.
6. Sube la versión de la cache de ocupación.

Los pasos 4 y 5 están juntos por una razón concreta: si la sesión se inserta y
el movimiento no, queda un vehículo adentro del registro que nadie puede ver y
que el conteo de lugares tampoco puede explicar.

**La hora es la del servidor, nunca la del equipo.** Si el equipo del operario
tiene el reloj mal puesto, el cobro no cambia.

### 2.5 Cuando el lugar ya está ocupado

Dos operarios pueden querer el mismo lugar en el mismo instante. No es un error:
es otro operario que entró primero.

El índice único parcial `unico_lugar_sesion_abierta` lo detecta, el servicio lo
traduce a un mensaje útil y devuelve **el lugar libre más cercano**. La
transacción se revierte entera: no queda ni vehículo huérfano ni movimiento sin
sesión.

**El operario elige, no el sistema.** El sistema no reasigna el lugar por su
cuenta.

---

## 3. Sale un vehículo

### 3.1 Los tres caminos

| Camino | Cuándo |
|---|---|
| Con el id de la sesión | El cliente tiene el ticket. Es el camino normal. |
| Con la patente | El cliente perdió el papel y el operador lo busca en pantalla. |
| Con el VIN | Hay dos vehículos de la misma familia con la misma patente, y la patente no sirve. |

Los tres terminan en el mismo método y, sobre todo, en la misma regla: **el
tiempo que se cobra sale de `ingreso_en` y `salida_en`**, que están en la base.
Nunca de un dato que mande el cliente.

Si hay varias sesiones que coinciden, el servicio **devuelve todas** para que
el operario elija. Elegir por el operario es inevitable, pero tiene que ser una
decisión visible, no un supuesto silencioso.

### 3.2 Lo que ocurre al cobrar

`RegistradorSalida::registrar()`, en **una sola transacción**:

1. Vuelve a leer la sesión con **bloqueo de fila**. Dos operarios no pueden
   cerrar la misma sesión a la vez: el segundo vería que ya tiene salida y no
   emitiría una segunda boleta por el mismo estacionamiento.
2. Calcula los segundos con `CalculadoraTiempo`, contra las marcas de la base.
3. Busca la tarifa que estaba vigente **el día del ingreso**.
4. Emite la boleta con su folio.
5. Guarda `salida_en` y `segundos_permanencia` en la sesión.
6. Crea el movimiento de tipo `salida`.

Las boletas y las salidas se emiten juntas **a propósito**: si pudieran quedar
separadas, un corte de luz entre las dos deja un vehículo afuera del registro
con un cobro hecho.

### 3.3 El cálculo

Solo hay dos tipos de tarifa, y son las únicas que el cálculo entiende:

| Tipo | Cómo cobra |
|---|---|
| `por_tiempo` | `valor` cada `minutos_por_fraccion` minutos |
| `por_evento` | `valor` fijo. El tiempo no cambia el monto. |

Las reglas del cobro por tiempo:

1. **La cortesía se resta antes de contar fracciones.** Con 10 minutos de
   cortesía, una estadía de 12 minutos cobra 2 minutos netos. Si se redondeara
   antes de restar, se cobraría de más.
2. **Una fracción incompleta se cobra como fracción completa.** Un auto que
   estuvo 21 minutos con fracciones de 20 paga dos fracciones, no una y media.
   El cálculo cuenta con `ceil` sobre **segundos**, que es lo que hace que un
   segundo de más no se pierda.
3. **Un descuento no puede pasar del total.** Es un cobro negativo, y eso no es
   un descuento.

El `desglose` de la boleta guarda el detalle tal como salió: método, tarifa,
minutos por fracción, valor, cortesía, fracciones y el tiempo desglosado. Si la
tarifa cambia mañana, ese detalle sigue explicando el cobro de hoy.

### 3.4 Cuando el cliente perdió el ticket

`POST /api/v1/estacionamientos/{id}/reconstruir`.

La sesión ya está cerrada y tiene `segundos_permanencia` guardado, así que la
cuenta se rehace **tal cual**: mismo tiempo, misma tarifa del día del ingreso,
mismo total.

**No es un cobro nuevo.** Emite una boleta nueva con folio nuevo y deja la
anterior como estaba. Las dos dicen lo mismo, y eso es lo que permite
responder "y por qué tengo dos tickets".

Se rechaza en dos casos, a propósito:

- Si el vehículo **sigue adentro**: el camino es el cobro normal.
- Si **ya tiene boleta**: se reimprime la existente, no se emite otra.

---

## 4. La mensualidad

### 4.1 Tres estados, y ninguno automático

| Estado | Significado |
|---|---|
| `activa` | El contrato está vigente |
| `suspendida` | El contrato existe pero no permite pasar |
| `terminada` | Se acabó el acuerdo. No hay nada que cobrar. |

**Una mensualidad no vence sola porque pasó la fecha.** El paso del tiempo es un
aviso, no una acción. La ejecuta una persona.

Un sistema que termina contratos automáticamente se equivoca de día —el
cambio de hora, un fin de semana, un feriado— y se lleva a un cliente que sí
pagó. Ese cliente no vuelve.

### 4.2 Registrar un pago

Renueva la credencial un mes por omisión. Con `acumular: true` el periodo
nuevo arranca cuando termina el que ya estaba pagado, para el cliente que paga
varios meses por adelantado.

Pagar también **levanta una suspensión**: el cliente está al día.

Un pago se anota en bitácora con su monto y su cantidad de meses, **sin el
nombre ni el RUT del titular**: la bitácora se entrega a terceros.

### 4.3 El aviso de vencimiento

`parqueadero:avisar-mensualidades`, una vez al día, escribe en bitácora las
mensualidades vencidas y las que vencen dentro de la ventana de anticipación.

**No manda correos ni mensajes.** No hay correo configurado, y escribir código
que finge mandar un correo es peor que no mandarlo. Lo que sí hace es dejar la
lista escrita y verificable.

---

## 5. Llega un reclamo

El orden en que se trabaja un reclamo es lo que lo hace resoluble:

### Paso 1 — Registrar

El cliente cuenta lo que pasó. Se guarda con **sus palabras**, la fecha del
hecho, lo que dice de la patente, su nombre y un contacto.

`patente_vista` es lo que el cliente dijo, que puede no coincidir con lo que
dice la base. **Esa diferencia es la pista.**

### Paso 2 — Buscar en el registro

`POST /api/v1/reclamos/contexto` con la patente y una ventana de fechas.

Se busca por **ventana de fechas**, no solo por el último ingreso: el cliente
recuerda "el martes a la tarde" mucho mejor que un momento exacto.

Esto se hace **antes** de escribir el reclamo, mientras el cliente sigue en la
ventanilla. Es el momento en que todavía se le puede preguntar algo.

Si no hay nada, la respuesta más probable es que el vehículo cambió de patente
o entró por otra sede. Y esa es una respuesta que el cliente puede aceptar.

### Paso 3 — Responder

- `abierto` → `en_revision` → `resuelto` o `rechazado`.

**Cerrar exige escribir qué se respondió.** La validación está en el formulario,
porque un reclamo que se cierra sin explicación deja al cliente sin respuesta y
a la empresa sin respaldo.

El paso 2 es el que justifica todo lo demás. Sin el registro, "el cliente dice
que no le cobramos" y "no cobramos" son la misma frase.

---

## 6. Lo que el operador nunca puede hacer

| Acción | Por qué no |
|---|---|
| Modificar un movimiento | El registro es evidencia. Se anota al lado. |
| Corregir el total de una boleta | Se anula con su motivo y se cobra de nuevo. |
| Terminar una mensualidad | Es una decisión de administración. |
| Forzar el cierre de un mes vencido | Una mensualidad no vence sola. |
| Cambiar el código de un lugar | Es lo que está pintado en el suelo. Se desactiva y se crea otro. |
| Subir su propio rol | Nadie sube su propio rol. |

---

## 7. Recorrido de prueba, de punta a punta

Con el operador de ejemplo de `php artisan db:seed`:

1. Entra y elige la sede **Vicuña Mackenna 1200**.
2. Mira **Ocupación**: 20 lugares activos, 2 ocupados, 18 libres. La capacidad
   declarada es 24 y no coincide con los 20 activos: el panel lo dice.
3. En **Ingresos**, busca la placa `ZSBB-30`. Es el mensualista **vencido**.
   El aviso dice que no paga desde hace dos meses.
4. Marca el ingreso en un lugar libre. La placa `KLPD-42` ya está adentro desde
   hace cuarenta minutos; busca un lugar libre, no ese.
5. En **Salidas**, busca `GTNR-19`: ese auto ya salió y pagó. No aparece como
   estacionado.
6. En **Salidas**, busca `PWRT-77` (el mensualista que entró hace cinco
   minutos). Mira el tiempo, cobra, y guarda el folio.
7. En **Boletas**, mira el total del día y la boleta nueva.
8. En **Reclamos**, busca `KLPD42` — la patente del reclamo **sin guion**. Es
   el vehículo del paso 4: el sistema lo encuentra igual.
9. En **Historial**, mira los movimientos del día.

Ese recorrido es el que hay que hacer antes de recibir operadores.