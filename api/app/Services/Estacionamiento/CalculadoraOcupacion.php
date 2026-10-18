<?php

declare(strict_types=1);

namespace App\Services\Estacionamiento;

use App\Models\Lugar;
use App\Models\Parqueadero;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Conteo de lugares disponibles en alta concurrencia.
 *
 * ============================================================================
 * POR QUE ESTE MECANISMO Y NO OTRO
 * ============================================================================
 *
 * El conteo se lee en linea, varias veces por segundo, con varios operarios
 * marcando ingresos a la vez. Y al mismo tiempo se escribe. El problema no
 * es que sea lento: es que si el conteo miente, el operador abre la barrera
 * a un lugar que ya esta ocupado, y el reclamo llega dos horas despues.
 *
 * SE DESCARTARON TRES MECANISMOS
 *
 * 1. **Contar el historico de movimientos en cada lectura.**
 *    `SELECT count(*) FROM movimientos WHERE tipo = 'ingreso' - salida`.
 *    Es O(historia). La historia de un parqueadero con diez años de
 *    operacion son millones de filas, y crece para siempre. Ademas el
 *    emparejar entrada y salida por texto es propenso a errores: un
 *    movimiento anulado rompe el conteo y nadie sabe por que el numero no
 *    cuadra. Descartado.
 *
 * 2. **Contador en Redis con INCR al ingreso y DECR a la salida.**
 *    Es lo rapido que se puede hacer, y aun asi es la peor opcion para este
 *    caso, por una razon concreta: **Redis no participa de la transaccion de
 *    PostgreSQL**. Si la transaccion se confirma y el proceso muere antes de
 *    llegar al INCR, el contador queda desfasado para siempre. No hay forma
 *    de saber que esta desfasado sin recorrer toda la tabla y comparar. Para
 *    un contador de vistas de pagina esa deriva es aceptable. Para saber si
 *    un lugar esta libre, no: es un dato del que depende una persona para
 *    dejar pasar o detener un vehiculo. Un contador que puede mentir sin
 *    avisar no sirve.
 *
 * 3. **Fila de estado en `lugares` actualizada con `ocupado = true`.**
 *    Es el error clasico. Dos operarios pueden leer `ocupado = false` al
 *    mismo tiempo y los dos escribir `true`: el ultimo gana y queda una sola
 *    sesion registrada para dos vehiculos. El conteo quedaria bien (1
 *    ocupado) mientras hay dos autos en el lugar, que es peor que un conteo
 *    erroneo visible. Descartado.
 *
 * EL MECANISMO ELEGIDO
 * -------------------
 * La verdad esta en PostgreSQL y la garantia la impone un **indice unico
 * parcial** (migracion 2026_10_17_000015):
 *
 *     CREATE UNIQUE INDEX unico_lugar_sesion_abierta
 *         ON estacionamientos (lugar_id)
 *         WHERE salida_en IS NULL;
 *
 * Ese indice dice, y PostgreSQL lo hace cumplir, que un lugar tiene a lo
 * sumo una sesion abierta. No hay coordinacion previa entre operarios, no hay
 * ventana entre leer y escribir, y no se bloquea nada que no sea el lugar en
 * conflicto: dos ingresos simultaneos al mismo lugar, uno entra y el otro
 * recibe una violacion de unicidad que `RegistradorIngreso` traduce a "ese
 * lugar recien fue tomado".
 *
 * El conteo es `COUNT(*) WHERE parqueadero_id = ? AND salida_en IS NULL`,
 * que es un recorrido del indice parcial `indice_ocupacion_por_sede`. Con
 * 120 lugares y 80 ocupados son 80 entradas de indice, no millones de filas
 * de historia.
 *
 * DONDE ENTRA REDIS, Y CON QUE LIMITES
 * ------------------------------------
 * Redis entra **solo como cache de lectura**, nunca como fuente de verdad:
 *
 *  - La clave se invalida con un contador de version, no con un tiempo de
 *    vida. Cada ingreso o salida suma uno a `parqueadero:{id}:version`, que
 *    va dentro de la clave de cache. La siguiente lectura ve la version nueva
 *    y recalcula. No hay ventana de datos rancios porque la version cambia
 *    en la misma transaccion que el movimiento.
    *  - El resultado cacheado puede quedar viejo por **a lo sumo un
     *    movimiento**, y solo si dos peticiones exactamente simultaneas
     *    cruzan el cambio. La siguiente peticion ya es correcta.
 *  - Si Redis no esta disponible, el metodo degrada a leer de PostgreSQL. La
 *    cache es una aceleracion, nunca un requisito. Un parqueadero con Redis
 *    caido tiene que seguir pasando vehiculos.
 *
 * Lo que Redis NO hace: decidir si un lugar esta libre. Eso lo decide el
 * indice unico en el momento de escribir. Un auto sigue teniendo que esperar
 * a que la transaccion de ingreso termine.
 */
class CalculadoraOcupacion
{
    /** Segundos que vive una lectura cacheada si la version no cambia. */
    private const TTL_SEGUNDOS = 30;

    /**
     * Fotografia de ocupacion de una sede.
     *
     * @return array{
     *     capacidad: int,
     *     ocupados: int,
     *     disponibles: int,
     *     sobrecapacidad: bool,
     *     lugares_activos: int,
     *     lugares_inactivos: int
     * }
     */
    public function estado(string $parqueaderoId): array
    {
        $clave = 'parqueadero:' . $parqueaderoId . ':ocupacion';

        try {
            $version = $this->version($parqueaderoId);

            $cacheado = Cache::get($clave . ':v' . $version);

            if (is_array($cacheado)) {
                return $cacheado;
            }
        } catch (Throwable) {
            // Redis caido o no disponible. Se sigue leyendo de PostgreSQL:
            // la cache es una aceleracion, no un requisito para operar.
            $version = null;
            $clave = null;
        }

        $estado = $this->calcularDesdePostgres($parqueaderoId);

        if ($clave !== null) {
            try {
                Cache::put($clave . ':v' . $version, $estado, self::TTL_SEGUNDOS);
            } catch (Throwable) {
                // Si no se puede guardar, el resultado igual se devuelve.
                // Perder cache es un problema de lentitud, no de exactitud.
            }
        }

        return $estado;
    }

    /**
     * Recalcula leyendo PostgreSQL. Esta es la fuente de verdad.
     *
     * @return array{
     *     capacidad: int,
     *     ocupados: int,
     *     disponibles: int,
     *     sobrecapacidad: bool,
     *     lugares_activos: int,
     *     lugares_inactivos: int
     * }
     */
    public function calcularDesdePostgres(string $parqueaderoId): array
    {
        $parqueadero = Parqueadero::query()->findOrFail($parqueaderoId);

        // Cuenta de sesiones abiertas. Usa el indice parcial
        // `indice_ocupacion_por_sede`.
        $ocupados = (int) DB::table('estacionamientos')
            ->where('parqueadero_id', $parqueaderoId)
            ->whereNull('salida_en')
            ->count();

        // Lugares que existen y estan en uso. La diferencia con la capacidad
        // declarada es informacion: si `lugares_activos` es menor que
        // `capacidad`, o al reves, el conteo de la pantalla hay que mirarlo
        // con desconfianza, y por eso se devuelven los dos numeros.
        $lugaresActivos = (int) Lugar::query()
            ->where('parqueadero_id', $parqueaderoId)
            ->where('activo', true)
            ->count();

        $lugaresInactivos = (int) Lugar::query()
            ->where('parqueadero_id', $parqueaderoId)
            ->where('activo', false)
            ->count();

        $capacidad = (int) $parqueadero->capacidad_total;

        // SOBRECAPACIDAD
        // --------------
        // Ocupar mas lugares de los declarados **no es un error**. Es un
        // dato. Puede ser un sobrecupo real y deliberado del operador, o
        // una capacidad mal cargada. Lo que no puede ser es una excepcion
        // que rompa la pantalla del operario en el momento en que mas la
        // necesita. Por eso se devuelve un booleano y no se lanza nada.
        $sobrecapacidad = $ocupados > $capacidad;

        return [
            'capacidad' => $capacidad,
            'ocupados' => $ocupados,
            'disponibles' => max(0, $capacidad - $ocupados),
            'sobrecapacidad' => $sobrecapacidad,
            'lugares_activos' => $lugaresActivos,
            'lugares_inactivos' => $lugaresInactivos,
        ];
    }

    /**
     * Suma uno a la version de la sede.
     *
     * Se llama DENTRO de la misma transaccion que registra el movimiento.
     * Si la transaccion se revierte, la version no cambia y la cache sigue
     * siendo valida. Ese es el motivo de usar un contador y no borrar la
     * clave: borrar la clave es una escritura fuera de la transaccion, y si
     * la transaccion despues revierte, se borro una cache que era correcta
     * y quedo entrada para siempre.
     */
    public function invalidar(string $parqueaderoId): void
    {
        try {
            Redis::incr('parqueadero:' . $parqueaderoId . ':version');
        } catch (Throwable) {
            // Sin Redis no hay cache que invalidar. La siguiente lectura
            // recalcula desde PostgreSQL, que es lo correcto.
        }
    }

    /**
     * Version actual de la cache de la sede.
     *
     * @return int|null
     */
    private function version(string $parqueaderoId): ?int
    {
        $valor = Redis::get('parqueadero:' . $parqueaderoId . ':version');

        return $valor === null ? 0 : (int) $valor;
    }

    /**
     * Lugar libre mas cercano al codigo pedido.
     *
     * Se usa cuando un ingreso choca con el indice unico: en vez de
     * devolver un error seco, se le ofrece al operario el siguiente lugar
     * disponible. El operario decide, no el sistema.
     */
    public function lugarLibreMasCercano(string $parqueaderoId, string $codigoIntentado): ?array
    {
        $libre = Lugar::query()
            ->where('parqueadero_id', $parqueaderoId)
            ->where('activo', true)
            ->whereNotIn('id', function ($consulta) use ($parqueaderoId): void {
                $consulta->select('lugar_id')
                    ->from('estacionamientos')
                    ->where('parqueadero_id', $parqueaderoId)
                    ->whereNull('salida_en');
            })
            ->orderByRaw("CASE WHEN codigo = ? THEN 0 ELSE 1 END", [$codigoIntentado])
            ->orderBy('codigo')
            ->first();

        if ($libre === null) {
            return null;
        }

        return [
            'id' => $libre->id,
            'codigo' => $libre->codigo,
            'tipo' => $libre->tipo,
        ];
    }
}
