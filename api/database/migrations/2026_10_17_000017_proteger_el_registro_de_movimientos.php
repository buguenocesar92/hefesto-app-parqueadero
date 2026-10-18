<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El registro no se edita: la garantia la impone la base, no el codigo.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * `movimientos` es la tabla de la que salen el ticket que se entrega al
 * cliente, la evidencia de un reclamo y la explicacion de una boleta meses
 * despues. Si una fila se puede modificar o borrar, entonces nada de eso es
 * confiable: el registro deja de ser un registro y pasa a ser una opinion.
 *
 * HASTA AHORA LA GARANTIA ERA UNA CONVENCION
 * ------------------------------------------
 * La migracion 2026_10_17_000010 dice que un movimiento no se actualiza ni se
 * borra, y las Policies no ofrecen metodos para hacerlo. Eso impide el
 * error por descuido, pero no el error a proposito: basta un `psql` con la
 * contrasena de la base, o una ruta de mantenimiento escrita con apuro, para
 * reescribir el pasado de un estacionamiento.
 *
 * Se eligio el mismo mecanismo que ya usa la aplicacion de almacen para el
 * stock (gatillo en PostgreSQL, no convencion en PHP), porque la conclusion
 * a la que llega el almacen es correcta: si la garantia importa, tiene que
 * vivir en el motor, que es lo unico que no se puede saltarse por olvido.
 *
 * QUE RECHAZA Y QUE NO
 * --------------------
 * Rechaza `UPDATE` y `DELETE` sobre `movimientos`. NO rechaza `INSERT`: el
 * registro crece, y crecer es lo correcto.
 *
 * Las anotaciones (`anotaciones_movimiento`) se dejan como estan a proposito:
 * agregar una aclaracion al lado de un hecho SI es el camino previsto para
 * "corregir", y no debe estar bloqueado por la misma regla.
 *
 * COMO SE CORRIGE ENTONCES UN MOVIMIENTO MAL REGISTRADO
 * -----------------------------------------------------
 * Con otro movimiento. Si se registro una salida que no ocurrio, se escribe
 * un movimiento de tipo `anulacion` que lo señala. El registro queda
 * completo y ademas cuenta la historia de lo que paso, que es justo lo que un
 * reclamo necesita ver.
 *
 * LO QUE ESTO NO CUBRE
 * ---------------------
 * El gatillo impide cambiar una fila. No impide que alguien con la contrasena
 * desactive el gatillo mismo. Eso ya exige acceso administrativo a la base,
 * que es un control distinto del que resuelve este archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.rechazar_edicion_de_movimientos()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = 'restrict_violation',
                    MESSAGE = 'La tabla movimientos es un registro y no se puede modificar ni borrar. Para corregir algo, escriba un movimiento de anulacion o una anotacion.',
                    HINT = 'Use INSERT para agregar un movimiento nuevo. La tabla anotaciones_movimiento si admite agregar aclaraciones.';
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON FUNCTION public.rechazar_edicion_de_movimientos() IS
                'Impide UPDATE y DELETE sobre el registro de movimientos. La inmutabilidad la impone la base, no el codigo.';
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS gatillo_movimientos_solo_insertar ON public.movimientos;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER gatillo_movimientos_solo_insertar
                BEFORE UPDATE OR DELETE ON public.movimientos
                FOR EACH ROW
                EXECUTE FUNCTION public.rechazar_edicion_de_movimientos();
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TRIGGER gatillo_movimientos_solo_insertar ON public.movimientos IS
                'El registro no se corrige: se anota al lado. Rechaza UPDATE y DELETE sobre movimientos.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gatillo_movimientos_solo_insertar ON public.movimientos');
        DB::unprepared('DROP FUNCTION IF EXISTS public.rechazar_edicion_de_movimientos()');
    }
};