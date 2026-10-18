# 00 - Índice

Aplicación de **Gestión de Estacionamientos** de Hefesto Studio.

Léelos en este orden la primera vez. No hace falta leerlos todos para
levantar el sistema: para eso están el [04](04-INSTALACION.md) y el
[05](05-CONFIGURACION.md).

---

## Los documentos

| # | Documento | De qué trata |
|---|---|---|
| 01 | [Arquitectura](01-ARQUITECTURA.md) | Cómo está armado el sistema y por qué cada parte está donde está |
| 02 | [Modelo de datos](02-MODELO-DE-DATOS.md) | Las catorce tablas, sus decisiones y lo que significan |
| 03 | [Multitenancy y roles](03-MULTITENANCY-Y-ROLES.md) | Cómo se impide que un operador vea los datos de otro |
| 04 | [Instalación](04-INSTALACION.md) | Cómo levantarlo desde cero |
| 05 | [Configuración](05-CONFIGURACION.md) | Cada variable de entorno y qué hace |
| 06 | [Ingreso de vehículo](06-INGRESO-VEHICULO.md) | El recorrido completo de un auto que entra, y el de uno que sale |
| 07 | [Seguridad](07-SEGURIDAD.md) | Qué datos se manejan, cuáles son personales y cómo se protegen |
| 08 | [Respaldos](08-RESPALDOS.md) | Cómo respaldar, cómo restaurar y por qué son críticos |
| 09 | [API](09-API.md) | Todos los endpoints, con sus códigos de error |
| 10 | [Pruebas y estado](10-PRUEBAS-Y-ESTADO.md) | **Qué está probado y qué no. Empieza por acá.** |

---

## Por dónde empezar

**Si vas a instalar:** [04](04-INSTALACION.md) →
[05](05-CONFIGURACION.md) → [08](08-RESPALDOS.md).

**Si vas a entender el sistema:** [01](01-ARQUITECTURA.md) →
[02](02-MODELO-DE-DATOS.md) → [06](06-INGRESO-VEHICULO.md).

**Si vas a tocar el código:** [03](03-MULTITENANCY-Y-ROLES.md) →
[09](09-API.md) → [10](10-PRUEBAS-Y-ESTADO.md).

**Si vas a poner esto en producción:** [07](07-SEGURIDAD.md) →
[08](08-RESPALDOS.md) → [10](10-PRUEBAS-Y-ESTADO.md).

---

## Las cinco ideas del sistema

Si solo te quedas con cinco cosas, que sean estas:

1. **El registro no se edita.** Un movimiento de `movimientos` no se puede
   modificar ni borrar, y no por una convención del código sino por un
   **gatillo en PostgreSQL**. Para corregir algo se anota al lado.

2. **Un lugar, a lo sumo un vehículo adentro.** No hay ningún campo
   `ocupado`. Lo garantiza un **índice único parcial** sobre las sesiones
   abiertas, que funciona aunque dos operarios aprieten el botón a la vez.

3. **El aislamiento entre operadores está en la base, no en el código.**
   PostgreSQL no devuelve filas de otra empresa, aunque la consulta se
   equivoque.

4. **El tiempo que se cobra sale de la base.** Nunca de lo que dice el
   cliente. Si pierde el ticket, la cuenta se reconstruye igual.

5. **La tarifa del día del ingreso.** Un auto que entró antes de un aumento
   se cobra con el precio de ese día, y esa boleta tiene que seguir
   explicando lo mismo dentro de un año.

---

## Glosario rápido

| Término | Qué significa |
|---|---|
| **Operador** | La empresa que usa el sistema. Un operador puede tener varias sedes. |
| **Sede** | Un estacionamiento físico, con su dirección y su zona horaria. |
| **Lugar** | Un puesto de estacionamiento. |
| **Sesión** | Un vehículo adentro, con su lugar y su hora de entrada. |
| **Movimiento** | Un hecho en el registro: un ingreso, una salida, una anulación. |
| **Boleta** | El documento de dinero de una salida. |
| **Mensualidad** | El derecho a pasar sin pagar por operación. |
| **Reclamo** | La queja de un cliente, con su investigación. |

Los términos van en **español de Chile**, con tildes. El nombre de las tablas
y de las columnas va sin tildes: es lo que se usa en código, y una tilde en
un identificador es una fuente de errores que no aporta nada.

---

## Estado, en una línea

**Código escrito, sin ejecutar.** No se corrió ni una migración, ni una
prueba, ni una construcción de imagen. Está todo anotado en
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md), y también lo que quedó
pendiente de verificar.