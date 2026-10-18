# 07 - Seguridad

---

## 1. Qué datos maneja este sistema

Antes de hablar de controles, hay que saber qué hay que proteger.

### Datos que NO son personales

| Dato | Por qué no es personal |
|---|---|
| Patente | Identifica un vehículo, no una persona. Es un bien. |
| VIN | Igual: identifica un vehículo de por vida, no a alguien. |
| Marca, modelo, color | Atributos de un objeto. |
| Hora de entrada y de salida | Hecho operacional. |
| Lugar ocupado | Estado de un espacio físico. |

Que un dato no sea "dato personal sensible" **no significa que sea inocuo**: la
combinación de una patente con una fecha y una hora permite reconstruir los
hábitos de una persona. Por eso este sistema los trata como información de la
empresa y controla su acceso igual.

### Datos que SÍ son personales

| Dato | Dónde está | Por qué es personal |
|---|---|---|
| **RUT del titular** | `mensualidades.cliente_rut` | **Identifica directamente a una persona.** Es el más sensible del sistema. |
| Nombre del titular | `mensualidades.cliente_nombre`, `reclamos.cliente_nombre` | |
| Correo y teléfono | `mensualidades.cliente_*`, `reclamos.cliente_contacto` | |
| **Relato del reclamo** | `reclamos.descripcion` | **Puede contener datos que el cliente no marcó como personales.** |
| RUT del operador | `tenants.rut` | Persona jurídica, pero identifica a una persona detrás. |
| **Fotos de vehículos** | `movimientos.foto_ruta`, `reclamos.foto_ruta` | **Una foto de un auto parked puede mostrar a las personas adentro, o una placa en otro auto.** |
| RUT y dirección IP | `usuarios.correo`, `bitacora.direccion_ip` | Datos de quien opera. |

### Sobre la Ley 19.628

Se menciona **de forma acotada**, y conviene ser preciso:

- La **Ley 19.628** regula el tratamiento de datos personales. Aplica a los
  datos de la columna anterior: nombre, RUT, correo, teléfono, relato de
  reclamos.
- **Los datos de vehículos y placas no son datos personales sensibles**, y no
  se presentan como tales. Son datos del bien.
- La Ley 20.584, que es la que regula los **datos sensibles**, no aplica a este
  sistema: no se manejan historias clínicas, ni orientación sexual, ni religión,
  ni afiliación política.

**Lo que este sistema NO hace, y es importante dejarlo dicho:**

1. **No hay un plazo de conservación configurado.** Ni para las fotos, ni para
   los reclamos, ni para los datos del titular. La columna no existe y no se
   inventa un número: la decisión legal depende de cada operador y de su
   relación con sus clientes, y un plazo inventado por un sistema es un plazo
   que después nadie puede defender.

2. **No hay exportación de datos del titular.** Un cliente que pide su RUT y su
   nombre, o el detalle de los movimientos de su vehículo, hoy se responde a
   mano con acceso a la base.

3. **No hay registro de consentimientos.** La relación contractual con el
   mensualista es la base lawful, pero eso lo formaliza el operador, no el
   software.

4. **No hay cifrado de la base.** El cifrado del disco es del servidor.

Los cuatro puntos quedan anotados como pendientes en
[10](10-PRUEBAS-Y-ESTADO.md). Están escritos como carencias, no como
funciones faltantes: son decisiones que dependen del operador.

---

## 2. Los controles que sí existen

### 2.1 El aislamiento entre operadores está en la base

Detallado en [03](03-MULTITENANCY-Y-ROLES.md). Lo que importa acá: no depende
de que cada consulta recuerde filtrar.

### 2.2 El dato personal no sale en los listados

Esto es una decisión de diseño, y es de las que más layering cuestan.

| Pantalla | Qué trae | Qué NO trae |
|---|---|---|
| Listado de mensualidades | Patente, nombre, estado, vencimiento | **RUT, correo, teléfono** |
| Ficha de mensualidad | Todo, **solo con `verContacto`** | |
| Listado de reclamos | Folio, fecha, estado, si hay foto | **Nombre, RUT, relato, foto** |
| Ficha de reclamo | Todo, **solo con `verFichaCompleta`** | |
| **Bitácora** | Ids, números, acciones | **Nunca: RUT, correo, teléfono, nombre de cliente.** |

La lógica está en el **servidor**, no en la pantalla:
`MensualidadController::presentar($mensualidad, conContacto: false)` y
`ReclamoController::presentar($reclamo, conFicha: false)`.

Que la decisión sea del servidor y no del navegador es lo importante: si fuera
de la interfaz, bastaría con abrir las herramientas de desarrollo para ver el
RUT de todos los clientes.

### 2.3 El registro no se puede reescribir

La migración `000017` pone un gatillo que rechaza `UPDATE` y `DELETE` sobre
`movimientos`. Un `psql` con la contraseña de la base tampoco puede acomodar el
pasado a la respuesta que le conviene dar a un reclamo.

### 2.4 El acceso es con identidad externa

No hay contraseñas en este sistema. Ni tabla, ni hash, ni siquiera una columna
vacía llamada `password`.

La razón: un formulario de acceso propio obliga al sistema a guardar algo que
permita recuperar una clave, y en un sistema donde se ven placas, nombres y RUT,
eso es el activo más robado que existe. Delegar en el proveedor de identidad
deja esa responsabilidad donde ya hay gente que la sabe tomar.

### 2.5 404 en vez de 403

Un 403 confirma que el recurso existe. Un 404 no. Como el filtro por operador
impide que el recurso exista a ojos del otro, 404 es además la respuesta
correcta.

### 2.6 La API no responde sin token CSRF

Laravel valida CSRF en el grupo `web`. La interfaz reenvía el token como
cabecera `X-XSRF-TOKEN`, que el navegador no puede leer porque la cookie es
`HttpOnly`. Sin eso, marcar un ingreso o cobrar una salida —acciones que mueven
datos de un cliente— quedarían sin protección.

La única excepción es `api/v1/salud`, que no pide credenciales a propósito: un
chequeo de salud que necesita sesión no lo puede correr el orquestador justo
cuando el servicio está caído.

### 2.7 Solo Caddy pública puertos

En producción, lo **único** publicado son los puertos 80 y 443 de Caddy.
PostgreSQL, Redis, la API y la interfaz se hablan por la red interna.

Publicar el 5432 es poner la base de datos a un escaneo de puertos de internet.

---

## 3. Las fotos: el punto más delicado

Una foto de un vehículo estacionado **puede mostrar a las personas que están
adentro**, o la placa de un auto que pasa por detrás. Es decir: una foto que
parece inocua puede contener dato personal.

Reglas que el sistema respeta:

1. **El relato del cliente no se toca.** No se resume, no se corrige.
2. **`foto_ruta` es `NOT NULL`** pero admite cadena vacía: un reclamo sin foto
   se registra con la foto vacía y se dice en la descripción que no hay
   evidencia. Es preferible un reclamo sin foto a un reclamo con una foto rota.
3. **Las fotos no están en el repositorio ni en la imagen de Docker.** Viven
   en un volumen, y `.gitignore` las excluye explícitamente.

Lo que **no** hace, y queda pendiente:

- No hay retención automática de fotos.
- No hay cifrado de las fotos en reposo.
- No hay forma de saber, desde el sistema, si una foto muestra o no a alguien.
  Esa revisión es de una persona.

---

## 4. El rol de la base de datos

La configuración de permisos que más importa:

```sql
-- Mal: esto desactiva el aislamiento por completo.
ALTER ROLE parqueadero_app SUPERUSER;

-- Mal: esto también.
ALTER ROLE parqueadero_app BYPASSRLS;

-- Bien:
CREATE ROLE parqueadero_app LOGIN PASSWORD '...';
GRANT CONNECT ON DATABASE parqueadero TO parqueadero_app;
GRANT USAGE ON SCHEMA public TO parqueadero_app;
```

Y para comprobarlo:

```sql
SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user;
```

Las dos columnas tienen que estar en `false`.

**Esto se verifica en cada arranque.** Si está mal, el contenedor no levanta.
No es una recomendación: es una condición de arranque.

---

## 5. Los registros

### La bitácora

Qué registra: quién hizo qué, cuándo, desde qué dirección IP.

Qué **no** registra: el cuerpo de las peticiones, los RUT, los correos, los
teléfonos, los nombres de cliente, ni el contenido de las fotos. La
configuración de Caddy lo dice explícitamente con `level INFO`.

Por qué: la bitácora es lo primero que se entrega a un tercero cuando hay que
transparentar información, y lo que se entrega tiene que poder leerse sin
convertirse en una segunda copia de los datos personales.

### El registro de movimientos

Es la evidencia. Por diseño, guarda **copias literales** de patente, VIN, tipo
y código de lugar, y no se puede modificar.

### El log del servidor

`APP_DEBUG=false` en producción. Con `true`, un error muestra rutas internas,
consultas SQL y a veces valores de variables.

---

## 6. Lo que falta

Escrito acá para que no se pase por alto:

1. **No hay revisión de seguridad externa** ni pruebas de penetración.
2. **No hay política de retención.** Ni para fotos, ni para reclamos, ni para
   los datos del titular. Ver la sección 1.
3. **No hay exportación de datos del titular.**
4. **No hay cifrado en reposo** de la base ni de las fotos.
5. **No hay registro de consentimientos.**
6. **No hay doble factor de autenticación** forzado. Depende de cómo esté
   configurado Authentik.
7. **Las imágenes de Docker están fijadas por tag, no por digest.** Un tag
   puede cambiar de contenido entre dos construcciones sin que cambie el
   nombre. Ver [10](10-PRUEBAS-Y-ESTADO.md).
8. **No hay análisis de dependencias** automatizado.

Ninguno de estos puntos se resuelve con una línea de código: los siete
primeros son decisiones del operador, y el octavo es un proceso.

---

## 7. Lo que hay que hacer antes de recibir clientes

- [ ] El rol de la aplicación **no** es superusuario ni tiene `BYPASSRLS`
- [ ] `parqueadero:verificar-aislamiento` sale con cuatro `[OK]`
- [ ] `APP_DEBUG=false`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `REDIS_PASSWORD` puesta
- [ ] `ACME_CORREO` es un correo real, para no quedarse sin TLS
- [ ] El certificado se emite y se renueva solo
- [ ] El directorio de fotos **no** es legible desde la web
- [ ] Hay una decisión escrita sobre **qué se hace con los datos cuando el
      cliente pide que se eliminen**. Sin esa decisión, el operador no puede
      responder, y responder es lo que la ley exige.
- [ ] Los operadores usan perfiles individuales en la caseta, no una cuenta
      compartida. Sin eso, la bitácora registra siempre a la misma persona y no
      sirve para investigar nada.