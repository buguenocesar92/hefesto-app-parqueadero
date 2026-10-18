<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indice unico parcial: un lugar, a lo sumo una sesion abierta.
 *
 * ESTA ES LA GARANTIA DE CONCURRENCIA DEL SISTEMA
 * -----------------------------------------------
 * El conteo de lugares disponibles se hace en linea, con varios operarios
 * marcando ingresos al mismo tiempo en la misma puerta. Si dos operarios
 * marcar dos ingresos al mismo tiempo en el mismo lugar, el lugar queda
 * con dos vehiculos y el conteo miente.
 *
 * Ese error se puede evitar en tres capas y aqui se elige la que no depende
 * de que todos escriban bien:
 *
 *  1. **`SELECT` y luego `INSERT` en la aplicacion.** Descartado. Entre el
 *     `SELECT` y el `INSERT` cabe otro ingreso. Con tres operarios en la
 *     misma puerta la condicion se pierde de forma intermitente, que es la
 *     peor forma de perderla: el operador ve el fallo un dia y no otro.
 *
 *  2. **Bloqueo pesimista (`SELECT ... FOR UPDATE`) sobre el lugar.**
 *     Funciona, pero serializa los ingresos de **todas** las puertas, no
 *     solo del mismo lugar, porque el operario no sabe de antemano en que
 *     lugar va a estacionar el cliente. Con un solo indice bloqueado, la
 *     puerta se convierte en el cuello de botella.
 *
 *  3. **Indice unico parcial.** Es la que se usa. PostgreSQL garantiza que
 *     no existan dos filas con el mismo `lugar_id` entre las que tienen
 *     `salida_en IS NULL`. Dos ingresos simultaneos al mismo lugar: uno
 *     entra y el otro recibe un error de violacion de unicidad. No hay
 *     coordinacion previa, no hay ventana de carrera y no se bloquea nada
 *     que no sea el lugar en conflicto.
 *
 * Que sea PARCIAL importa: el indice solo mira las filas con
 * `salida_en IS NULL`. Un lugar puede tener mil historial de sesiones y
 * sigue cumplindo, porque el indice no guarda las cerradas.
 *
 * El indice tambien hace barato el conteo: `COUNT(*)` sobre sesiones
 * abiertas es un recorrido del indice, no de la tabla completa.
 *
 * COMO SE MANEJA EL CHOQUE
 * -----------------------
 * El error de unicidad no se muestra crudo al operario. El servicio de
 * ingreso lo traduce a "ese lugar recien fue tomado" y devuelve el lugar
 * libre mas cercano. Ver App\Services\Estacionamiento\RegistradorIngreso.
 *
 * El indice **no** impide la sobrecapacidad de la sede: que haya mas
 * vehiculos que lugares es un dato de la pantalla, no un error. Solo impide
 * que dos vehiculos ocupen el mismo lugar a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS unico_lugar_sesion_abierta
                ON public.estacionamientos (lugar_id)
                WHERE salida_en IS NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON INDEX public.unico_lugar_sesion_abierta IS
                'Garantiza que un lugar tenga a lo sumo una sesion abierta. Es el mecanismo de concurrencia del conteo de lugares.';
        SQL);

        // Indice de apoyo para el conteo por sede. Es un indice parcial
        // sobre las sesiones abiertas, que es exactamente el conjunto que
        // se cuenta en pantalla.
        DB::unprepared(<<<'SQL'
            CREATE INDEX IF NOT EXISTS indice_ocupacion_por_sede
                ON public.estacionamientos (parqueadero_id)
                WHERE salida_en IS NULL;
        SQL);

        // Indice de apoyo para buscar el historico por fecha: es lo que
        // permite reconstruir la cuenta de un reclamo de ayer.
        DB::unprepared(<<<'SQL'
            CREATE INDEX IF NOT EXISTS indice_historial_por_sede_y_ingreso
                ON public.estacionamientos (tenant_id, parqueadero_id, ingreso_en);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS public.unico_lugar_sesion_abierta');
        DB::unprepared('DROP INDEX IF EXISTS public.indice_ocupacion_por_sede');
        DB::unprepared('DROP INDEX IF EXISTS public.indice_historial_por_sede_y_ingreso');
    }
};
