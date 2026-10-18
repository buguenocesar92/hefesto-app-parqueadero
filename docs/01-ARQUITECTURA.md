# 01 - Arquitectura

---

## 1. Qué resuelve este sistema

Un estacionamiento no tiene un problema de registro. Tiene tres, y se
confunden entre sí:

1. **¿Hay lugar?** El operador tiene que saberlo en el momento, con varios
   automóbiles entrando al mismo tiempo por la misma puerta. Si el número
   miente, abre la barrera a un lugar que ya está ocupado, y el reclamo llega
   dos horas después.

2. **¿Cuánto se cobra?** La cuenta sale del tiempo que estuvo adentro. Si esa
   cuenta no se puede reconstruir después, cada cliente sin ticket es una
   discusión sin salida.

3. **¿Qué pasó ese día?** Un reclamo llega semanas después: "estacioné el
   martes a la tarde y me cobraron mal". Sin el registro de ese día, no hay
   respuesta, y la respuesta es lo que sostiene la relación con el cliente.

El sistema existe para responder esas tres preguntas. Todo lo demás —la
interfaz, los colores, el tiempo de respuesta— es menos urgente que eso.

---

## 2. Las cuatro piezas

```
                    Internet
                       │
                  ┌────▼────┐
                  │  Caddy  │   TLS. Único puerto público: 80 y 443.
                  └────┬────┘
                       │
            ┌──────────┴──────────┐
            │                     │
    ┌───────▼────────┐   ┌────────▼───────┐
    │  Nuxt (web)    │   │  Laravel (api)  │
    │  puerto 3000   │   │  puerto 9000    │
    └────────────────┘   └────────┬────────┘
                                  │
                    ┌─────────────┼─────────────┐
                    │             │             │
            ┌───────▼──────┐ ┌────▼─────┐ ┌─────▼──────┐
            │ PostgreSQL   │ │  Redis   │ │  Authentik │
            │ 5432         │ │ 6379     │ │ (externo)  │
            │ RLS + gatillo│ │ cache    │ │ identidad  │
            └──────────────┘ └──────────┘ └────────────┘
              NO se publica    NO se publica
```

| Pieza | Qué es | Por qué está ahí |
|---|---|---|
| **Laravel** | La API. Aplica las reglas de negocio. | Es donde vive la lógica y donde se decide. |
| **PostgreSQL** | La autoridad del aislamiento y de la inmutabilidad. | Es lo único que no se puede saltar por olvido. |
| **Nuxt** | La interfaz y el servidor intermedio (BFF). | El navegador nunca habla directo con la API. |
| **Redis** | Cache de ocupación, colas, sesión. | Acelera. No decide nada. |
| **Authentik** | Identidad. | El sistema no guarda contraseñas. |

**Lo que NO hay:** un solo campo en una sola base donde estén todos los datos
de todos los operadores con un filtro en cada consulta. Eso se explica en
[03](03-MULTITENANCY-Y-ROLES.md).

---

## 3. Por qué el navegador no habla con la API

Nuxt no es solo la interfaz: también es un proxy. El navegador pide a Nuxt,
Nuxt pide a Laravel.

Eso permite tres cosas que de otro modo no se pueden tener:

1. **La cookie de sesión viaja en el mismo origen.** Sin CORS, sin
   configurarlo dos veces, y sin exponer la API a peticiones desde cualquier
   sitio.

2. **La dirección interna de la API no se pública.** El visitante no puede
   saber que existe algo en `api:9000`.

3. **El token CSRF viaja como cabecera.** La API acepta `X-XSRF-TOKEN`, que
   el navegador no tiene que leer: la cookie es `HttpOnly` y no se puede leer
   desde JavaScript.

Está implementado en `web/server/api/[...ruta].ts`.

---

## 4. Dónde vive cada decisión

La parte más importante de la arquitectura de este sistema es **dónde está
cada regla**, porque una regla en el lugar equivocado se puede saltar.

| Regla | Dónde vive | Por qué ahí |
|---|---|---|
| Un lugar, un vehículo | **Índice único parcial** en PostgreSQL | Porque dos operarios pueden apretar a la vez, y el código no sabe de la carrera |
| El registro no se edita | **Gatillo** en PostgreSQL | Porque un `psql` con la contraseña también puede escribir |
| El aislamiento entre operadores | **RLS** en PostgreSQL | Porque una consulta mal escrita no puede filtrar datos |
| Cuánto se cobra | `CalculadoraTarifa` (código) | Es aritmética, no una restricción de integridad |
| Qué tarifa se aplica | `RegistradorSalida` (código) | Necesita leer la fecha de ingreso |
| Quién puede anular una boleta | **Policy** de Laravel | Es una decisión de rol, no de integridad |
| Que un mensualista esté al día | `VerificadorDeAcceso` (código) | Es una regla que AVISA, no que bloquea |

La línea que separa las dos mitades: **si la regla es "esto no puede
existir", va en la base. Si la regla es "quién tiene permiso para hacerlo",
va en el código.**

---

## 5. La ocupación: la parte más difícil

Es lo que más merece una explicación, y está detallada en
`api/app/Services/Estacionamiento/CalculadoraOcupacion.php`.

El problema: contar lugares libres, en línea, con varios operarios marcando
ingresos al mismo tiempo.

### Lo que se descartó y por qué

**Contar el historial de movimientos.** Es `ingresos - salidas`. Es O(historia),
y en un estacionamiento con diez años son millones de filas. Además, emparejar
entradas y salidas por texto es propenso a errores: un movimiento anulado
rompe el conteo y nadie sabe por qué.

**Un contador en Redis con `INCR` al ingreso y `DECR` a la salida.** Es lo más
rápido que se puede hacer, y aun así es la peor opción, por un motivo
concreto: **Redis no participa de la transacción de PostgreSQL**. Si la
transacción se confirma y el proceso muere antes de llegar al `INCR`, el
contador queda desfasado para siempre, y no hay forma de saberlo sin recorrer
toda la tabla. Para un contador de vistas de página esa deriva es aceptable.
Para saber si un lugar está libre, no: de eso depende una persona para dejar
pasar o detener un vehículo.

**Una columna `ocupado` en `lugares`.** Es el error clásico. Dos operarios
leen `ocupado = false` al mismo tiempo y los dos escriben `true`: el último
gana y queda **una sola sesión registrada para dos vehículos**. El conteo
queda bien —"1 ocupado"— mientras hay dos autos en el lugar, que es peor que
un conteo erróneo visible, porque nadie lo nota.

### Lo que se usó

Un **índice único parcial**:

```sql
CREATE UNIQUE INDEX unico_lugar_sesion_abierta
    ON estacionamientos (lugar_id)
    WHERE salida_en IS NULL;
```

- Dos ingresos simultáneos al mismo lugar: uno entra y el otro recibe un
  error de unicidad. No hay coordinación previa, no hay ventana de carrera.
- Que sea **parcial** importa: el índice solo mira las filas abiertas. Un
  lugar puede tener mil visitas y sigue cumpliendo.
- El índice también hace barato el conteo: `COUNT(*)` sobre sesiones abiertas
  es un recorrido del índice, no de la tabla.

El error no se muestra crudo al operario. `RegistradorIngreso` lo traduce a
"ese lugar se tomó hace un instante" y devuelve **el lugar libre más cercano**.
El operador elige, no el sistema.

### El caché no decide

`CalculadoraOcupacion::estado()` guarda el resultado 30 segundos en Redis.
Si Redis está caído, recalcula contra PostgreSQL. **El caché nunca decide si un
lugar está libre**: siempre se puede leer la verdad. Perder caché es un
problema de lentitud, no de exactitud.

---

## 6. El registro es lo más valioso del sistema

`movimientos` es la tabla de la que salen el ticket que se entrega al
cliente, la evidencia de un reclamo y la explicación de una boleta meses
después.

Por eso está protegida en dos capas:

1. **No hay ninguna ruta de la aplicación que la modifique.** Ni `update`, ni
   `delete`. La única escritura posible es agregar.
2. **La base no lo permite tampoco.** La migración
   `2026_10_17_000017` levanta un gatillo que rechaza `UPDATE` y `DELETE`
   sobre `movimientos`. Un `psql` con la contraseña de la base tampoco puede
   reescribir el pasado de un estacionamiento.

Para corregir algo se **anota al lado** (`anotaciones_movimiento`) o se
escribe un movimiento nuevo de tipo `anulacion`. El hecho queda como fue, y la
aclaración queda con su autor y su fecha.

---

## 7. Las fotos

La foto de un vehículo es evidencia. Es lo que permite demostrar que el auto
estaba estacionado, o que el lugar estaba vacío cuando el cliente llegó.

Hoy se guarda **la ruta**, y el archivo lo sube la interfaz. La columna
`foto_obligatoria` existe pero está en `false` y **no se debe activar sin
montar el volumen**: si se exige la foto y el archivo no está, la fila queda
con una ruta que no lleva a nada, y el registro de ese vehículo no tiene la
evidencia que el caso del reclamo asume que tiene.

Ver el pendiente en [10](10-PRUEBAS-Y-ESTADO.md).

---

## 8. El modelo de versioning chileno

Este sistema **no tiene un modelo de versioning de documentos**. No es un
gestor documental, no emite boletas electrónica y no tiene una normativa
tributaria que verificar.

Lo que sí tiene, y conviene no confundir con eso:

- **Boleta de estacionamiento**: un documento interno de cobro del operador,
  con folio propio por operador. No es factura electrónica y no se transmite
  al Servicio de Impuestos Internos.
- **RUT del operador y del mensualista**: el RUT identifica a una persona
  natural o jurídica. Es dato personal. Ver [07](07-SEGURIDAD.md).

No se inventa un número de documento tributario ni un enlace a un servicio
externo. Si el operador necesita emitir factura electrónica, eso es otro
sistema: este cobra el estacionamiento.

---

## 9. Dónde está cada cosa

```
parqueadero/
├── docs/          los once documentos que estás leyendo
├── api/           Laravel 13 + PostgreSQL con RLS
│   ├── app/
│   │   ├── Models/          catorce tablas
│   │   ├── Policies/        quién puede hacer qué
│   │   ├── Services/        las reglas de negocio
│   │   ├── Http/            controladores y validación
│   │   ├── Jobs/            tareas diferidas
│   │   └── Console/         comandos de consola
│   └── database/migrations/ diecisiete migraciones
├── web/           Nuxt con render en el servidor
└── deploy/        Docker Compose, imágenes y respaldos
```

---

## 10. Lo que este sistema NO hace

Conviene decirlo antes de que alguien lo descubra después:

- **No cierra ninguna puerta.** Una mensualidad vencida produce un aviso. La
  barrera la abre una persona. Un sistema que cerrara el portón por una regla
  dejaría al operador sin poder abrir en una emergencia.
- **No manda correos ni mensajes.** Deja avisos en bitácora. Ver
  [06](06-INGRESO-VEHICULO.md).
- **No emite factura electrónica.** Emite boleta interna de cobro.
- **No reconoce placas ni lee la matrícula.** El VIN se escribe.
- **No reconcilia contra la caja.** Dice cuánto se cobró según el sistema; si
  el dinero en la gaveta no calza, eso lo ve una persona.
- **No borra nada.** Ni movimientos, ni boletas, ni reclamos. Se anulan o se
  desactivan, y el registro queda.