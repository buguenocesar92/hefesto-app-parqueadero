# 02 - Modelo de datos

Catorce tablas. Este documento explica qué es cada una y, sobre todo, **por
qué está como está**, porque casi todas las decisiones parecen obvias solo
después de haber tomado la contraria.

---

## 1. El mapa

```
tenants (operadores)          sin RLS: se lee en el acceso
  │
  ├── usuarios                las personas, con su rol
  ├── configuraciones         una fila por operador
  │
  ├── parqueaderos             las sedes físicas
  │     ├── lugares           los puestos de estacionamiento
  │     └── tarifas           los precios, con vigencia
  │
  ├── vehiculos               el padrón, por VIN
  │     └── mensualidades     el derecho a pasar sin pagar
  │
  ├── estacionamientos        una sesión: un auto adentro
  │     ├── movimientos       EL REGISTRO (no se edita)
  │     │     └── anotaciones_movimiento
  │     └── boletas           el documento de dinero
  │
  └── reclamos                las quejas de los clientes

bitacora                      quién hizo qué (agregado, no relacionado)
```

Treinta y una columnas de clave ajena, seis índices únicos y tres
restricciones que no son de unicidad. Todo eso está en las migraciones
`000001` a `000017`.

---

## 2. Las tres decisiones que explica todo lo demás

### 2.1 `lugares` NO tiene una columna `ocupado`

Un lugar está ocupado **si existe una fila en `estacionamientos` con ese
`lugar_id` y `salida_en` nulo**.

La razón está escrita entera en
`api/app/Services/Estacionamiento/CalculadoraOcupacion.php`: con una columna
`ocupado`, dos operarios leen `false` al mismo tiempo y los dos escriben
`true`. Queda **una sesión para dos vehículos**, y el conteo dice "1 ocupado"
mientras hay dos autos adentro. Es peor que un conteo erróneo visible, porque
nadie lo nota.

En su lugar hay un **índice único parcial**:

```sql
CREATE UNIQUE INDEX unico_lugar_sesion_abierta
    ON estacionamientos (lugar_id)
    WHERE salida_en IS NULL;
```

### 2.2 `movimientos` guarda copias literales, no referencias

`movimientos.patente`, `vin`, `tipo_vehiculo` y `lugar_codigo` **no son
llaves foráneas**. Son el valor que se escribió ese día.

La razón es concreta: si mañana alguien corrige la patente de un vehículo, los
movimientos antiguos deben seguir mostrando la placa que se veía ese día. Un
reclamo se resuelve mirando el registro, y el registro no se reescribe.

Que la copia no pueda quedar contradictoria con el vehículo está garantizado
por construcción: `RegistradorIngreso` copia los dos valores en la **misma
transacción**, desde el mismo objeto.

### 2.3 Las tarifas no se borran ni se editan cuando ya cobraron

`vigente_desde` y `vigente_hasta` existen para que una boleta de marzo se
pueda explicar dentro de un año. `RegistradorSalida` busca la tarifa vigente
por la **fecha del ingreso**, no por la de hoy.

Para cambiar el precio se crea una tarifa nueva y se cierra la anterior con
`vigente_hasta`. La tarifa vieja queda, con el valor con el que se cobró.

La única tarifa editable es la que **aún no entra en vigencia**: ahí corregir
un error de tipeo no reescribe nada, porque todavía no cobró nadie.

---

## 3. Tabla por tabla

### `tenants` — el operador

| Columna | Tipo | Nota |
|---|---|---|
| `id` | uuid | |
| `slug` | varchar(64) | único. Es el dominio del correo. |
| `nombre` | varchar(160) | |
| `razon_social` | varchar(200) | nullable |
| `rut` | varchar(20) | nullable. **Dato personal** del operador. |
| `correo_contacto` | varchar(200) | nullable |
| `telefono_contacto` | varchar(40) | nullable |
| `activo` | boolean | default `true` |

**Es la única tabla sin `tenant_id` y sin RLS**, a propósito: se consulta
durante el acceso, cuando todavía no hay sesión y por lo tanto no hay
`app.tenant_id` que poner. Ver [03](03-MULTITENANCY-Y-ROLES.md).

### `usuarios` — las personas

`authentik_subject` es único y es lo que vincula la cuenta externa. `rol` es un
string de 32 con cuatro valores: `administrador`, `supervisor`, `operario`,
`lectura`.

`ultimo_ingreso` se actualiza en el callback de acceso, **no en el
middleware**. Así la marca dice "la última vez que entró por el proveedor de
identidad", y no "la última vez que hubo una petición", que no sirve para
nada.

No hay `password`. Ni hash, ni siquiera vacío. La autenticación es de
Authentik.

### `configuraciones` — una fila por operador

| Columna | Default | Nota |
|---|---|---|
| `mensualidad_vencida_bloquea_acceso` | `true` | Ver la nota importante de abajo. |
| `minutos_cortesia` | `10` | **No participa del cálculo.** Ver abajo. |
| `minutos_redondeo` | `1` | **No participa del cálculo.** Ver abajo. |
| `zona_horaria` | `America/Santiago` | |
| `dias_aviso_vencimiento` | `15` | |

Hay un índice único sobre `tenant_id`: no puede haber dos configuraciones por
operador, y no hay que elegir cuál es la buena.

> **Nota importante sobre `minutos_cortesia` y `minutos_redondeo`.**
>
> Estas dos columnas **se guardan y se muestran, pero el cobro no las lee.**
> El cálculo real usa `tarifas.minutos_incluidos` y
> `tarifas.minutos_por_fraccion`, que son **por tarifa**.
>
> Es una discrepancia real entre lo que el modelo declara y lo que el código
> hace, y se detectó al revisar. No se corrigió, y por qué:
>
> - Conectarlas cambiaría el monto que cobran los estacionamientos que ya
>   tienen boletas emitidas. Cambiar un cobro en curso sin avisar es
>   exactamente lo que este sistema evita.
> - La decisión es del operador: ¿cortesía por sede o por tarifa? Son dos
>   modelos de negocio distintos, y ninguno es el correcto por omisión.
>
> Está anotado como pendiente en [10](10-PRUEBAS-Y-ESTADO.md). La interfaz lo
> declara: `ConfiguracionController` devuelve
> `minutos_cortesia_en_uso: false` para que no se prometa algo que no pasa.

`mensualidad_vencida_bloquea_acceso` **no cierra ninguna puerta**. Produce un
aviso. La barrera la abre una persona. Ver
[06](06-INGRESO-VEHICULO.md).

### `parqueaderos` — las sedes

`capacidad_total` es lo que el operador **declara**. No es la cantidad de
lugares: esa se cuenta en `lugares`.

Se guardan los dos porque **no siempre coinciden**, y cuando no coinciden hay
que poder decirlo. `OcupacionController::estado()` devuelve `coincide: false` y
el panel avisa. Un sistema que elige una de las dos y esconde la diferencia
termina prometiendo un puesto que no existe.

### `tarifas` — los precios

**Solo hay dos tipos de tarifa**, y son los únicos que
`CalculadoraTarifa::calcular()` entiende:

| `tipo` | Qué hace | Campos que necesita |
|---|---|---|
| `por_tiempo` | Cobra `valor` cada `minutos_por_fraccion` minutos | `minutos_por_fraccion` obligatorio, mayor que 0 |
| `por_evento` | Precio fijo. El tiempo no cambia el monto. | Solo `valor` |

Cualquier otro valor en `tipo` **hace fallar el cobro** con una excepción, no
cobra una cifra equivocada. Es deliberado: el fallo aparece en la pantalla del
operador al guardar la tarifa, no en la barrera con un cliente esperando.

> El modelo de este proyecto proponía `por_hora`, `por_dia` y `mes` como
> tipos de tarifa. **Esos tres no los acepta el cálculo**, que solo reconoce
> `por_tiempo` y `por_evento`. El modelo se ajustó a lo que el código hace
> realmente, y no al revés.

`minutos_incluidos` son los minutos que **no** se cobran, por tarifa. Se
descuentan de la permanencia antes de contar fracciones.

`tipo_vehiculo` es opcional: si viene vacío, la tarifa aplica a cualquier
vehículo.

`activo = false` más `vigente_hasta` es cómo se retira una tarifa, sin borrar.

### `lugares` — los puestos

`codigo` es único **por sede**, no global: dos sedes pueden tener cada una su
`A-01`.

`tipo` distingue los lugares que no son intercambiables: `visitante`,
`mensualista`, `moto`, `carga`, `accesible`. El sistema **no** impide que un
mensualista ocupe un lugar de visitante ni al revés: son reglas del operador y
dejarlas fijas en el sistema sería decidir por él. Lo que sí se hace es
mostrar el tipo en el mapa.

`activo = false` es cómo se retira un lugar. **No se borra**: el registro de
movimientos guarda el código del lugar tal como estaba ese día, y un código
borrado deja movimientos sin lugar al que apuntar.

### `vehiculos` — el padrón

**La clave es el VIN, no la patente.** La patente se cambia, se roba y se
vuelve a asignar. El VIN identifica al vehículo de por vida.

El padrón **no se llena a mano**: `RegistradorIngreso` crea la fila la
primera vez que el auto entra. Un padrón escrito a mano se desactualiza solo:
en dos meses la mitad de los autos ya no están.

`patente` es una foto del momento. La que tenía el día del reclamo está en
`movimientos.patente`, que no se toca nunca.

### `mensualidades` — tres fechas, tres problemas distintos

Esta es la parte que más se confunde al mirar, y por eso está aquí:

| Columna | Qué es | Qué problema representa |
|---|---|---|
| `fecha_inicio` / `fecha_termino` | El **acuerdo comercial** | Se acabó el contrato: no hay nada que cobrar, hay que devolver el lugar. |
| `fecha_ultimo_pago` | Si está **al día con la plata** | Contrato vigente, plata pendiente. |
| `credencial_vence_en` | Hasta cuándo la **credencial sirve** | Pagó y quedó sin permiso para pasar. Se arregla renovando, no cobrando. |

**Si las tres fechas se mezclaran en una columna**, un cliente que pagó pero
quedó sin credencial se vería igual que uno que no pagó, y el operador
terminaría cobrando a quien ya no tiene contrato.

Estados: `activa`, `suspendida`, `terminada`.

> **Una mensualidad no vence sola por que pasó la fecha.** El paso del tiempo
> es un aviso, no una acción. La ejecuta una persona. Un sistema que termina
> contratos automáticamente se equivoca de día y se lleva a un cliente que sí
> pagó.

`cliente_nombre`, `cliente_rut`, `cliente_correo` y `cliente_telefono` son
**dato personal**. Ver [07](07-SEGURIDAD.md).

### `estacionamientos` — la sesión

Es el contrato con el cliente:

- `ingreso_en` y `salida_en` son la **única fuente de verdad** del tiempo
  cobrado. Son `timestamptz`: los guarda PostgreSQL en UTC, así que la
  duración es correcta en cualquier zona horaria y no hay ambigüedad con el
  cambio de hora.
- `segundos_permanencia` se calcula al cerrar y se guarda. No es una
  redundancia sin propósito: es el resultado que se facturó. Si la forma de
  calcular cambia mañana, la boleta de ayer tiene que seguir cuadrando.
- `boleta_id` se llena al emitir. Es la única llave que va en círculo: no tiene
  restricción de clave foránea porque `boletas` ya referencia a esta tabla, y
  una referencia cruzada no se puede expresar con dos restricciones.

Está abierta si `salida_en` es nulo.

### `movimientos` — el registro

`operador_id` y `usuario_id` son uuid **sin** restricción de clave foránea.
Son personas que pueden haber sido dadas de baja y cuya fila sigue ahí. La
atribución de un hecho a una persona es parte del registro.

Tipo: `ingreso`, `salida`, `anulacion`.

**No se actualiza ni se borra.** La migración `000017` lo garantiza con un
gatillo. Para corregir, se anota al lado o se escribe una anulación.

### `anotaciones_movimiento` — la aclaración

La única forma de "corregir" el registro sin tocarlo. El hecho queda como fue,
la aclaración queda al lado, con su autor y su fecha.

**No tiene `created_at` ni `updated_at`**: tiene `creado_en`, y no tiene valor
por omisión. Por eso el modelo `AnotacionMovimiento` declara
`$timestamps = false` y hay que llenarlo a mano.

A diferencia de `movimientos`, esta tabla **sí** admite escritura: agregar una
aclaración es el camino previsto.

### `boletas` — el dinero

- `folio` es único **por operador** y lo genera el sistema, nunca la pantalla.
- `desglose` guarda el detalle del cálculo tal como salió. Es lo que permite
  explicar la cuenta meses después, aunque la tarifa cambie. La columna es
  `jsonb`.
- Los importes son enteros, sin decimales: **CLP no tiene céntimos**.
- `estado` es `emitida` o `anulada`. Anular **no borra y no cambia el
  total**: se marca con el motivo y el documento queda.
- `anulacion_motivo` admite 300 caracteres y es obligatorio: sin texto,
  "anulada" no explica nada a nadie.

### `reclamos` — donde se junta el dato personal

Un reclamo contiene nombre, RUT, teléfono, la foto del incidente y el relato
del cliente con sus palabras. Es el único lugar del sistema donde se juntan
datos personales de alguien que no es usuario del sistema.

Decisiones que conviene notar:

- `folio` es corto y dictable por teléfono (`R-000001`). El `id` interno es un
  UUID que no se puede leer en voz alta sin equivocarse.
- `fecha_incidente` y `hora_incidente` están separadas porque el cliente
  recuerda "el martes a la tarde" mucho mejor que "el martes a las 15:20". La
  hora puede quedar en `null` y se busca el día completo.
- `patente_vista` es lo que el cliente dijo, que puede no coincidir con lo que
  dice la base. Esa diferencia **es la pista del reclamo**, y por eso se
  guarda aparte.
- `descripcion` es la versión del cliente, no se resume ni se corrige.
- `foto_ruta` es `NOT NULL`. Sin foto se deja la cadena vacía y se dice en la
  descripción que no hay evidencia.

Estados: `abierto`, `en_revision`, `resuelto`, `rechazado`. Las transiciones
las valida `EstadoReclamo::puedeIrDesde()`.

### `bitacora` — quién hizo qué

La **única tabla con id entero grande** (`bigIncrements`) en lugar de UUID,
porque es un registro de solo agregado que crece sin que nadie lo consulte por
id. Por eso `Bitacora` no usa el rasgo `HasUuids`: es la excepción consciente
del patrón.

`creado_en` **no tiene valor por omisión**. La tiene que llenar la aplicación,
y por eso la escritura pasa por un solo lugar:
`RegistradorDeBitacora::registrar()`.

`datos` guarda contexto de la acción: identificadores, números. **No se
guardan RUT, correo, teléfono ni nombre de cliente.**

---

## 4. Lo que deliberadamente NO tiene

| No existe | Por qué |
|---|---|
| Un campo `ocupado` en `lugares` | Ver 2.1. Es el error clásico de concurrencia. |
| Una columna `contratado` en `mensualidades` | Tres fechas separadas, porque son tres problemas distintos. |
| Una tabla `pagos` | El pago se registra con una fecha en la mensualidad. Una tabla de pagos sería la forma correcta, y es una decisión pendiente, no una omisión. |
| Un campo `total_esperado` en `lugares` | El total lo da la suma de boletas, y se calcula en la base. Guardarlo sería una segunda verdad que puede discrepar. |
| Un `deleted_at` en cualquier tabla | Nada se borra. Se desactiva con `activo = false`. |

---

## 5. Índices y su motivo

| Índice | Para qué |
|---|---|
| `unico_lugar_sesion_abierta` (único, parcial) | **La garantía de concurrencia del sistema.** |
| `indice_ocupacion_por_sede` (parcial) | El conteo de ocupados es un recorrido de índice, no de tabla. |
| `unico_boleta_folio_por_operador` | Dos boletas con el mismo folio no pueden existir. |
| `unico_reclamo_folio_por_operador` | Lo mismo para reclamos. |
| `indice_movimientos_por_patente_y_fecha` | La búsqueda de un reclamo: qué entró con esa placa en esa fecha. |
| `indice_movimientos_por_vin_y_fecha` | Igual, por VIN, que es el identificador que no cambia. |
| `indice_historial_por_sede_y_ingreso` | Reconstruir una cuenta de un reclamo de ayer. |
| `indice_bitacora_por_fecha` | La bitácora se consulta por fecha, siempre. |

---

## 6. Ver la verdad

Los nombres de tabla y de columna están en las migraciones. Para ver el
esquema sin instalar nada:

```sh
grep -n "tabla->" api/database/migrations/*.php
```

Y para ver el aislamiento:

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
    exec api php artisan parqueadero:verificar-aislamiento
```

**Ninguna de las dos se ha ejecutado en el equipo donde se escribió esto.**
Ver [10](10-PRUEBAS-Y-ESTADO.md).