# 03 - Multitenancy y roles

---

## 1. Qué hay que evitar primero

En un estacionamiento, el riesgo más caro no es que falle una pantalla. Es
que el operador de **Vicuña Mackenna** vea el registro de movimientos y los
reclamos del operador de **Ñuñoa**, con los nombres y RUT de sus clientes.

Todo lo demás —la interfaz, la rapidez— es menos urgente que eso.

Por eso el aislamiento **no puede depender solo del código**. Se implementa en
dos capas:

| Capa | Qué hace | Qué detecta |
|---|---|---|
| Aplicación (Policies y middleware) | Decide y filtra | Errores de lógica, consultas mal escritas |
| **PostgreSQL (Row Level Security)** | **Impide que PostgreSQL devuelva filas ajenas** | **Todo lo que se le escape a la capa de arriba** |

La base es la última línea. Si el código tiene un error, la base sigue sin
entregar datos de otro operador.

---

## 2. El patrón: columna `tenant_id` + RLS

Todas las tablas de negocio llevan `tenant_id`, y PostgreSQL aplica una
política que exige que esa columna sea igual a la de la petición.

**Este patrón no es una invención de esta aplicación.** Se tomó de la decisión
ya escrita y probada en `apps/portal-autoservicio/docs/03-MULTITENANCY.md` y en
`apps/almacen/docs/03-MULTITENANCY-Y-ROLES.md`, y se reutilizó tal cual. No se
inventó un cuarto enfoque.

### Por qué no un esquema por empresa

La alternativa clásica es darle a cada empresa su propio esquema
(`cliente_1.movimientos`, `cliente_2.movimientos`). Se evaluó y se descartó:

1. **Las migraciones se multiplican.** Con esquema por cliente, cada migración
   tiene que aplicarse en TODOS los esquemas: con 30 empresas son 30
   ejecuciones, con 300 son 300.

2. **El pool de conexiones se vuelve el cuello de botella**, porque hay que
   dimensionarlo por el número de combinaciones esquema/rol que se usan de
   verdad.

3. **Laravel obliga a duplicar la lógica de esquema.** Un `DB::table()`
   olvidado, una relación mal definida.

4. **Las herramientas de administración se complican**: respaldar una empresa
   sería `pg_dump -n cliente_1`, lo que obliga a scripts a medida.

5. **El aislamiento queda en manos de la aplicación.** El `search_path` es un
   parámetro de sesión: si queda mal puesto, la consulta va al esquema
   equivocado y **no falla** — devuelve los datos de la empresa equivocada sin
   error visible.

### Por qué entonces columna única + RLS

- Una migración, un esquema, un pool.
- La política se aplica en la base, no en PHP: no se puede "olvidar".
- Un `where tenant_id = ?` olvidado no fuga datos: filtra de más, que es un
  error visible.
- Respaldar es `pg_dump` de una base, sin scripts por empresa.

El costo es que **todas** las consultas necesitan contexto de empresa. Ese
costo está resuelto y forzado por diseño.

---

## 3. El mecanismo, en detalle

Definido en
`api/database/migrations/2026_10_17_000014_activar_row_level_security.php`.

### La función de contexto

```sql
CREATE FUNCTION public.app_tenant_id() RETURNS uuid
    valor := current_setting('app.tenant_id', true);
    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION USING ERRCODE = 'insufficient_privilege', ...
```

**Lanza excepción** si no hay operador. Podría devolver `NULL` y filtrar todo
en silencio, pero se eligió que falle de forma ruidosa:

- Si devuelve `NULL`, la consulta devuelve cero filas sin aviso. Alguien lo
  "arregla" devolviendo `NULL` cuando hace falta (un reporte, un comando de
  consola) y la puerta queda abierta sin que nada lo detecte.
- Si lanza excepción, el error aparece en el log **en el momento**, y se sabe
  exactamente qué operación se escapó del middleware.

Es el mismo criterio que un candado: importa que falle cerrado.

### La política

```sql
ALTER TABLE public.movimientos ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.movimientos FORCE ROW LEVEL SECURITY;

CREATE POLICY politica_aislamiento_movimientos ON public.movimientos
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Tres detalles que importan:

- `USING` filtra lo que se **lee**.
- `WITH CHECK` impide **escribir** una fila con otro `tenant_id`. Sin esto, un
  `INSERT` con el operador equivocado pasaría la lectura y dejaría una fila
  invisible que nadie podría ver ni borrar.
- `FORCE ROW LEVEL SECURITY` hace que la política aplique **también al
  dueño** de la tabla. Sin `FORCE`, el dueño la saltaría, y en desarrollo el
  rol de la aplicación suele ser el dueño: sin `FORCE`, el aislamiento sería
  decorativo justo en el entorno donde se prueba.

Las trece tablas de negocio están protegidas: `usuarios`,
`configuraciones`, `parqueaderos`, `tarifas`, `lugares`, `vehiculos`,
`mensualidades`, `estacionamientos`, `movimientos`, `anotaciones_movimiento`,
`boletas`, `reclamos` y `bitacora`.

### Cómo viaja el operador

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $tenantId]);
```

El `true` final es lo más importante del archivo: significa **alcance de
transacción**. Al confirmar o revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (ámbito de sesión), el valor sobreviviría a la petición. Y
el **worker de colas reutiliza la misma conexión** para trabajos de operadores
distintos: un `SET` a sesión significa que un trabajo de Vicuña Mackenna podría
leer los datos de Ñuñoa. Ese es exactamente el fallo que este diseño evita, y
por eso `ContextoTenant::establecer()` **lanza excepción si no está dentro de
una transacción**.

### Quién fija el contexto

Solo una clase, `App\Services\Tenancy\ContextoTenant`, y solo a través del
middleware `App\Http\Middleware\ResolverTenant`, que envuelve la petición
autenticada en una transacción con el operador ya fijado.

El middleware `AuditarOperacion` va **después**, y por eso corre dentro de esa
transacción: si se invirtieran, la bitácora se escribiría sin contexto y la RLS
la rechazaría.

### La tabla `tenants` es la excepción consciente

`tenants` **no lleva RLS**, a propósito: la empresa se resuelve durante el acceso,
cuando todavía no hay sesión y por lo tanto no hay `app.tenant_id` que poner. Por
eso `tenants` es la excepción controlada del patrón.

Dos compensaciones:

1. **La vista `vw_tenants_publicos`** (migración
   `2026_10_17_000016_crear_vista_tenants_publicos.php`, que expone `slug`,
   `nombre` y `activo` de los operadores activos). **Hoy queda sin uso**: esta
   aplicación **no tiene selector de empresa**. No hay ruta que la liste ni que la
   consulte antes de autenticarse; el operador se resuelve desde la sesión, por la
   relación `empresa` del usuario ya autenticado. La única lectura de `tenants` en
   código de ejecución es la del diagnóstico `GET /contexto`
   (`AuthController::contexto`), que trae la fila por identificador y solo devuelve
   el nombre. La vista no se eliminó: se conserva como defensa en profundidad.

   Sobre lo que se expone: `tenants` guarda `razon_social`, `rut`, `correo_contacto`
   y `telefono_contacto`, pero **ninguno sale hacia el exterior antes de
   autenticarse**. Lo que llega al cliente son columnas de identificación (`slug`,
   `nombre`), nunca datos de contacto ni datos de vehículos o clientes.

   **Pendiente, no comprobado:** activar la vista exige revisar qué espera la
   interfaz y si algún flujo necesita algo más que el nombre. No se ha podido
   comprobar contra una base de datos porque no había una disponible, así que la
   decisión sigue abierta. Una versión anterior de este documento afirmaba que la
   aplicación leía la vista antes de autenticarse; era falso y se corrige aquí.

2. **El rol de la aplicación no es superusuario.** Quien tenga acceso SQL
   directo puede leer `tenants` entera. Por eso el despliegue exige revisar los
   permisos y `parqueadero:verificar-aislamiento` comprueba que el rol no sea
   superusuario ni tenga `BYPASSRLS`.

---

## 4. Doble filtro dentro del operador

La RLS garantiza que nada sale del operador. Dentro del operador hay un segundo
filtro, en las Policies.

| Acción | `lectura` | `operario` | `supervisor` | `administrador` |
|---|:---:|:---:|:---:|:---:|
| Ver ocupación, lugares, tarifas | Sí | Sí | Sí | Sí |
| Ver movimientos y historial | Sí | Sí | Sí | Sí |
| Marcar ingresos y salidas | No | Sí | Sí | Sí |
| Registrar pagos de mensualidades | No | Sí | Sí | Sí |
| Registrar y mover reclamos | No | Sí | Sí | Sí |
| Ver la ficha completa de un reclamo | No | Sí | Sí | Sí |
| Ver el contacto del titular de una mensualidad | No | No | **Sí** | **Sí** |
| **Anular una boleta** | No | No | No | **Sí** |
| **Resolver o rechazar un reclamo** | No | No | **Sí** | **Sí** |
| Crear y editar tarifas | No | No | **Sí** | **Sí** |
| Crear y editar lugares | No | No | **Sí** | **Sí** |
| **Terminar una mensualidad** | No | No | No | **Sí** |
| **Cambiar la configuración del operador** | No | No | No | **Sí** |
| **Cambiar el rol de una persona** | No | No | **Sí** | **Sí** |
| Ver y editar cuentas | No | No | No | **Sí** |

Las líneas en negrita son las que cambian **historia o dinero**:

- **Anular una boleta** cambia el total cobrado. Si cualquiera que opere
  pudiera hacerlo, habría una vía para desviar caja sin dejar rastro, porque la
  boleta no se borra pero sí se marca.
- **Resolver un reclamo** es el fin de una investigación. Lo puede hacer el
  supervisor, que es quien conoce el negocio, pero no el operario: cerrar un
  reclamo es una decisión, no un trámite.
- **Terminar una mensualidad** devuelve el lugar y corta el acuerdo. Que lo
  haga un operario por un reclamo mal gestionado es un cliente perdido.

Dos reglas adicionales:

1. **Nadie sube su propio rol.** `UsuarioPolicy::cambiarRol()` lo rechaza
   cuando el objetivo es uno mismo. La separación de poderes también aplica
   dentro de la aplicación.
2. **El listado de mensualidades no trae el dato de contacto.** El RUT, el
   correo y el teléfono del titular solo salen en la ficha completa, que exige
   `verContacto`. El listado se mira decenas de veces al día; la ficha, unas
   pocas. Ver [07](07-SEGURIDAD.md).

### 404, no 403

Cuando una Policy rechaza, la respuesta es **404, no 403**: un 403 confirmaría
que el recurso existe. Como el filtro por operador impide que el recurso exista
a ojos del otro, 404 es además la respuesta correcta.

Está configurado en `api/bootstrap/app.php`.

### El menú no protege

La interfaz esconde los botones que el rol no va a poder usar. Eso es una
cortesía, no una protección. La autorización real la hace la API.

Si el menú se equivoca, el operador ve un botón que al apretarlo no hace nada.
Si la autorización se equivocara, un operario vería datos de otro operador. El
costo de equivocarse en el menú es mucho menor, y por eso el menú puede
simplificarse y la autorización no.

---

## 5. El registro también está protegido

El aislamiento dice "este operador no ve los datos de otro". El gatillo de la
migración `000017` dice "nadie modifica el registro, ni siquiera su propio
operador".

Son dos cosas distintas:

| Garantía | Mecanismo | Migración |
|---|---|---|
| Un operador no ve datos de otro | RLS | `000014` |
| Un operador no reescribe su propio pasado | Gatillo | `000017` |

La segunda importa tanto como la primera: un reclamo que se resuelve mirando el
registro solo vale si el registro no se puede acomodar a la respuesta que se
quiere dar.

---

## 6. Qué comprueba `parqueadero:verificar-aislamiento`

Se corre en cada despliegue y en cada arranque (el entrypoint lo invoca).

1. Que el rol **no** sea superusuario ni tenga `BYPASSRLS`.
2. Que las trece tablas de negocio tengan RLS **habilitado y forzado**, y su
   política con `USING` y `WITH CHECK`.
3. Que la función de contexto **siga fallando cerrada** sin `app.tenant_id`.
4. Que exista el gatillo `gatillo_movimientos_solo_insertar`.

**Si algo falla, el contenedor no levanta php-fpm.** Un sistema parado es un
problema visible; uno que mezcla datos de dos operadores es un problema que
aparece meses después, en un reclamo.

Y hay un quinto control, dentro de la API: `SaludController` reporta
`degradado` —no `ok`— si la función de contexto no existe, porque un servicio
de pie sin aislamiento es peor que uno caído.

---

## 7. Riesgos que siguen abiertos

Con todas las letras:

1. **No hay revisión de seguridad externa** ni pruebas de penetración.

2. **Las pruebas de aislamiento están escritas pero no se ejecutaron** (no
   había PostgreSQL disponible). Ver
   [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

3. El `slug` de un operador se puede enumerar antes de autenticarse, por el
   dominio del correo. Un atacante puede descubrir que Hefesto opera un
   estacionamiento para cierta empresa, pero no ve más que su nombre y su
   razón social, que es información pública de cualquier empresa.

4. Los operarios acceden desde equipos compartidos en la caseta. El uso de un
   perfil por operario, y no una cuenta compartida, es la primera condición
   para que la bitácora diga algo útil. Sin eso, la bitácora registra siempre a
   la misma persona y no sirve para investigar nada.