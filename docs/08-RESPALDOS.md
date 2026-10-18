# 08 - Respaldos

En un estacionamiento, un respaldo no es una lista de clientes. Es la
respuesta a "el auto estaba acá el martes y le cobramos mal". Los movimientos,
los lugares y las fotos son la evidencia con la que se responde un reclamo.

Un respaldo que solo tenga totales no sirve para nada: los totales no explican
nada.

---

## 1. Las seis reglas que siguen los scripts

Están en el código, no solo acá, y cada una está puesta por un motivo.

| Regla | Por qué |
|---|---|
| **1. Manifiesto con SHA-256 de cada archivo** | Un respaldo sin suma de verificación es una carpeta con archivos: no hay forma de saber si se copió entero ni si se dañó. |
| **2. Se marca completo SOLO al final** | Si el script se corta a la mitad, el directorio queda sin la marca y se sabe que no sirve. |
| **3. Las rutas se validan antes de escribir** | Un `mkdir -p` a una ruta equivocada deja 40 GB donde nadie los busca. |
| **4. Destino ocupado se rechaza, salvo `--force`** | Que se repita el nombre significa dos respaldos en el mismo segundo, o un reintento. |
| **5. Nunca hay `DROP` ni sobrescritura automática** | Un respaldo que borra el anterior se queda sin respaldo. |
| **6. La retención avisa antes de borrar** | Si el criterio está mal, se ve antes de que sea tarde. |

Las reglas 1 y 2 son las que se agregaron después de aprender que un
respaldo a medias **parece** un respaldo: la carpeta existe, el archivo pesa lo
que tiene que pesar, y el fallo aparece al restaurar, que es el peor momento.

---

## 2. Cómo se hace un respaldo

```sh
cd /opt/parqueadero
set -a; . ./.env; set +a
./deploy/respaldos/respaldo.sh
```

Variables que necesita:

| Variable | Para qué |
|---|---|
| `DIRECCION_RESGARDOS` | Dónde se dejan los respaldos |
| `DB_HOST`, `DB_PORT` | |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | |
| `RETENCION_DIAS` | Por omisión, 30 |

### Qué produce

Un directorio por respaldo:

```
/var/backups/parqueadero/parqueadero-20261017-020000/
├── datos.dump.gz     el volcado comprimido
├── manifiesto.txt    SHA-256, tamaño, versión de pg_dump, fecha
└── COMPLETO          la marca: se escribe AL FINAL
```

Y si el script se corta, queda además `EN_CURSO`, que dice que ese respaldo
**no** sirve.

### Por qué un directorio y no un archivo suelto

Porque un respaldo son tres archivos que van juntos: el volcado, su suma y su
marca. Un `.dump.gz` suelto en una carpeta se puede copiar sin su manifiesto y
terminar en un equipo donde nadie sabe de dónde salió.

### Qué hace, en orden

1. **Comprueba** que estén las variables, que exista `pg_dump` y que haya una
   utilidad de SHA-256.
2. **Valida la ruta**: que el destino exista, sea un directorio, se pueda
   escribir, y tenga al menos 500 MB libres. **Si el destino no existe, el
   script no lo crea y avisa**: crear un directorio es exactamente el momento
   en que se escribe el respaldo donde nadie lo va a buscar.
3. **Rechaza** un destino ocupado, salvo `--force`.
4. **Vuelca** en un archivo sin comprimir y comprime al final. Si se comprimiera
   al vuelo y fallara, quedaría un `.gz` truncado que parece un respaldo.
5. **Verifica** que el archivo se puede leer (`gzip -t`).
6. **Escribe el manifiesto** con la suma SHA-256.
7. **Aplica la retención**, imprimiendo cada carpeta que va a eliminar.
8. **Marca `COMPLETO`**, y solo entonces.

### Formato

Formato **personalizado** de PostgreSQL (`--format=custom`), comprimido. Permite
restaurar tablas sueltas, que es lo que hace falta cuando se daña una tabla y
no todo lo demás. Con `--no-owner`, el respaldo no exige un rol que puede que ya
no exista cuando hay que restaurar.

---

## 3. Cómo se restaura

### Primero, siempre, la simulación

```sh
./deploy/respaldos/restauracion.sh --simular /var/backups/parqueadero/parqueadero-20261017-020000
```

No escribe nada. Comprueba el archivo, el manifiesto, la suma SHA-256 y las
dependencias. **Correr esto antes es obligatorio.** Un respaldo dañado
restaurado a medias deja una base que parece buena y no lo está.

### Después, la restauración real

```sh
./deploy/respaldos/restauracion.sh /var/backups/parqueadero/parqueadero-20261017-020000
```

Y **el servicio queda detenido**. Ese script no lo vuelve a levantar. Es
deliberado: restaurar con gente entrando al estacionamiento produce boletas de
vehículos que el sistema no tiene, y eso ya no tiene arreglo.

### Si la base de destino tiene tablas

El script **aborta**:

```
[restauracion] ERROR: la base de destino ya tiene tablas.
[restauracion]        no se sobrescribe nada sin confirmacion.
```

La decisión de qué hacer con los datos que hay es de una persona, no de un
script. Si de verdad se quiere reemplazar:

```sh
./deploy/respaldos/restauracion.sh --force /ruta/al/respaldo
```

Con `--force` el script **vacia las tablas** con `TRUNCATE` en una sola
transacción. **No borra la base y no hay ningún `DROP` en el script.** La base
sigue existiendo, con su propietario y sus permisos.

### Lo que hace la restauración

1. Verifica que el respaldo tenga la marca `COMPLETO`.
2. Verifica la **suma SHA-256 contra el manifiesto**. Si no calza, no restaura
   nada.
3. Comprueba que el archivo comprimido no esté dañado.
4. Consulta cuántas tablas tiene la base de destino, y para si hay alguna y no
   se pasó `--force`.
5. **Detiene el servicio** (`docker compose stop api web`).
6. Vacía las tablas existentes, si hace falta.
7. Restaura con `--single-transaction --exit-on-error`.

Las dos banderas finales importan mucho:

- **`--single-transaction`**: la restauración se aplica **completa o no se
  aplica**. Una restauración a medias deja una base incoherente que parece
  buena.
- **`--exit-on-error`**: se detiene en el primer error, en vez de seguir
  aplicando como si nada.

**No se usa `--clean`**, que genera `DROP` por dentro. La base de destino está
vacía justamente para que no haga falta.

### Después de restaurar, y antes de levantar nada

```sh
php artisan parqueadero:verificar-aislamiento
php artisan parqueadero:verificar-registro
```

Y una revisión manual del total de boletas. Recién después:

```sh
docker compose -f deploy/docker-compose.prod.yml --env-file .env up -d
```

---

## 4. La copia externa

**El script de respaldo NO copia nada a otro lado, a propósito.**

Cada instalación decide adónde va su copia: una NAS, un servicio de objetos, otro
servidor. Esa decisión no se puede tomar desde un script genérico, y adivinar es
peor que no hacerlo.

Lo que sí hace el script es recordar, al final de cada corrida:

```
[respaldo] recordatorio: este respaldo sigue en el servidor.
[respaldo] falta copiarlo fuera y probar una restauracion completa.
```

Un respaldo que está en el mismo servidor que respalda **no es un respaldo**.
Es una copia de convenience.

### Una copia externa mínima

| Dónde | Cada cuánto | Con qué |
|---|---|---|
| Otra máquina o NAS | Todos los días | `rsync` o `rclone` |
| Service de objetos (S3, compatible) | Todos los días | `rclone sync` |

Y hay que **verificar la copia**, no solo hacerla: un `rsync` que falla en
silencio durante tres semanas es indistinguible de uno que funcionó.

---

## 5. La rutina

```cron
# Respaldo diario, 02:00.
0 2 * * * cd /opt/parqueadero && set -a && . ./.env && set +a && ./deploy/respaldos/respaldo.sh >> /var/log/parqueadero-respaldo.log 2>&1
```

Y **una restauración completa, ensayada, cada mes.** En una base vacía. Es lo
único que demuestra que el respaldo sirve.

Un resumen que debería salir bien:

```cron
# Restauración de ensayo, día 1 de cada mes, 04:00.
0 4 1 * * cd /opt/parqueadero && set -a && . ./.env && set +a && ./deploy/respaldos/restauracion.sh --simular /var/backups/parqueadero/parqueadero-$(date -d '1 day ago' +\%Y\%m\%d)-020000 >> /var/log/parqueadero-respaldo.log 2>&1
```

---

## 6. Qué NO está respaldado

| Cosa | Por qué | Qué hacer |
|---|---|---|
| Las **fotos** | Viven en el volumen `volumen-fotos`, no en la base | Respaldar el volumen aparte |
| El **`.env`** | Está en el disco y tiene los secretos | Vault, o un gestor de secretos |
| Los **certificados TLS** | Volumen `volumen-certificados` | Se regeneran solos, pero tardan |
| El **código** | Es un repositorio | No debería estar en el servidor |

**Las fotos son el punto que más se pasa por alto.** Un respaldo de la base sin
las fotos es un respaldo donde los reclamos de los clientes no tienen evidencia
que mostrar.

```sh
# Las fotos, aparte.
docker run --rm -v parqueadero_volumen-fotos:/datos -v /var/backups/parqueadero:/respalda \
    alpine tar czf /respalda/fotos-$(date +%Y%m%d).tar.gz -C /datos .
```

Ese comando **no** valida nada y **no** calcula ninguna suma. Es una guía del
camino, no un respaldo. Para hacerlo bien hay que escribir el script con
manifiesto y SHA-256, igual que el de la base.

---

## 7. Señales de alarma

| Señal | Qué significa |
|---|---|
| No hay respaldo nuevo en dos días | El cron no está corriendo, o el directorio de respaldos se llenó |
| El log dice "el archivo esta danado" | Falla de disco, o se cortó el respaldo |
| No hay `COMPLETO` en el último | El respaldo se cortó. **No sirve.** |
| `gzip -t` falla | El archivo está dañado en reposo |
| La suma no calza con el manifiesto | El archivo cambió después de escribirse |
| La restauración dice "ya tiene tablas" | Alguien la está corriendo sobre la base viva |

La señal que más importa es la primera: **un respaldo que deja de crecer es un
respaldo que dejó de existir**, y se descubre el día que hace falta.

---

## 8. Lo que no se pudo verificar

- **Los scripts no se ejecutaron.** No había Docker, ni PostgreSQL, ni un
  servidor donde probarlos. Se validó su **sintaxis** con `bash -n`, que
  comprueba que el shell los pueda leer, y nada más.
- **No se probó una restauración real.** El camino feliz y los caminos de error
  están escritos, pero ninguno se ejecutó.
- **La copia externa no está implementada**, y hay que elegir una.

Todo esto está en [10](10-PRUEBAS-Y-ESTADO.md).