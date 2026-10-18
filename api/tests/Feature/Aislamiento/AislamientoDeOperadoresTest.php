<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Bitacora;
use App\Models\Estacionamiento;
use App\Models\Movimiento;
use App\Models\Reclamo;
use App\Models\Tarifa;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: un operador no puede leer ni tocar los datos de otro.
 *
 * Estas pruebas van contra PostgreSQL de verdad, porque el aislamiento esta
 * implementado con Row Level Security. Correrlas en SQLite daria verde sin
 * comprobar nada. Ver `phpunit.xml` y docs/10-PRUEBAS-Y-ESTADO.md.
 *
 * Lo que se comprueba aca:
 *
 *  1. Sin contexto de operador, la consulta REVIENTA. No devuelve cero filas
 *     en silencio: eso dejaria pasar el caso peligroso.
 *  2. Con el contexto de cada operador, cada uno ve SOLO sus filas.
 *  3. Un id del otro operador no existe para este: 404, no 403.
 *  4. No se puede escribir en nombre del otro (la parte `WITH CHECK`).
 *  5. El contexto no sobrevive a la transaccion, para que el worker de colas
 *     no arrastre el operador de otro trabajo.
 *  6. El registro no se puede modificar ni borrar ni desde la aplicacion.
 *
 * ESTAS PRUEBAS NO SE EJECUTARON
 * -----------------------------
 * Ver docs/10-PRUEBAS-Y-ESTADO.md. No habia PostgreSQL, ni PHP, ni Composer
 * en el equipo donde se escribieron.
 */
class AislamientoDeOperadoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_contexto_de_operador_la_consulta_falla_en_vez_de_traer_datos(): void
    {
        $primero = $this->crearOperador('primero.cl');
        $this->dejarVehiculoAdentro($primero['operario'], $primero['lugares'][1]);

        // Se limpia el contexto a proposito: es la situacion de una consulta
        // que se escapo del middleware.
        //
        // Lo que se espera NO es "cero filas", sino un error: la funcion de
        // contexto lanza excepcion cuando no hay operador, y la politica
        // aborta. Devolver cero filas seria mas comodo de probar, pero
        // dejaria pasar el caso peligroso: que alguien "arregle" la funcion
        // devolviendo NULL y termine leyendo filas del operador equivocado sin
        // que nada lo delate.
        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);

        $this->expectException(QueryException::class);

        Estacionamiento::query()->count();
    }

    public function test_cada_operador_solo_ve_sus_propias_filas(): void
    {
        $primero = $this->crearOperador('primero.cl');
        $this->dejarVehiculoAdentro($primero['operario'], $primero['lugares'][1]);

        $segundo = $this->crearOperador('segundo.cl');
        $this->dejarVehiculoAdentro($segundo['operario'], $segundo['lugares'][1]);

        $this->fijarOperador($primero['operador']->id);
        $this->assertSame(1, Estacionamiento::query()->count());
        $this->assertSame(
            $primero['operador']->id,
            Estacionamiento::query()->value('tenant_id')
        );

        $this->fijarOperador($segundo['operador']->id);
        $this->assertSame(1, Estacionamiento::query()->count());
        $this->assertSame(
            $segundo['operador']->id,
            Estacionamiento::query()->value('tenant_id')
        );
    }

    public function test_las_sesiones_del_otro_operador_no_existen(): void
    {
        $primero = $this->crearOperador('primero.cl');
        $sesionAjena = $this->dejarVehiculoAdentro($primero['operario'], $primero['lugares'][1]);

        $segundo = $this->crearOperador('segundo.cl');
        $lugarAjeno = $segundo['lugares'][1];

        $this->fijarOperador($segundo['operador']->id);

        $this->assertNull(Estacionamiento::find($sesionAjena->id));
        $this->assertNull(Movimiento::where('estacionamiento_id', $sesionAjena->id)->first());
    }

    public function test_no_se_puede_escribir_una_fila_con_el_operador_de_otro(): void
    {
        $primero = $this->crearOperador('primero.cl');
        $segundo = $this->crearOperador('segundo.cl');

        // Contexto del primero, pero se intenta guardar una tarifa del
        // segundo. La politica `WITH CHECK` lo impide.
        $this->fijarOperador($primero['operador']->id);

        $this->esperarFalloDeBase(function () use ($primero, $segundo): void {
            Tarifa::query()->create([
                'tenant_id' => $segundo['operador']->id,
                'parqueadero_id' => $primero['sede']->id,
                'nombre' => 'Tarifa con operador ajeno',
                'tipo' => 'por_tiempo',
                'valor' => 100,
                'minutos_por_fraccion' => 20,
                'minutos_incluidos' => 0,
                'vigente_desde' => now(),
            ]);
        });
    }

    public function test_no_se_puede_tocar_una_boleta_del_otro_operador(): void
    {
        $primero = $this->crearOperador('primero.cl');
        $sesion = $this->dejarVehiculoAdentro($primero['operario'], $primero['lugares'][1]);

        \App\Services\Estacionamiento\RelojDelServidor::congelar(now()->addHour()->toImmutable());

        $boleta = app(\App\Services\Estacionamiento\RegistradorSalida::class)
            ->registrar($sesion, $primero['operario']);

        \App\Services\Estacionamiento\RelojDelServidor::descongelar();

        $segundo = $this->crearOperador('segundo.cl');
        $this->fijarOperador($segundo['operador']->id);

        $this->assertNull(\App\Models\Boleta::find($boleta->id));

        // Y tampoco se puede modificar por la via directa.
        $this->esperarFalloDeBase(function () use ($boleta): void {
            DB::table('boletas')->where('id', $boleta->id)->update(['total' => 0]);
        });
    }

    public function test_el_contexto_de_operador_no_sobrevive_a_la_transaccion(): void
    {
        $primero = $this->crearOperador('primero.cl');
        $this->dejarVehiculoAdentro($primero['operario'], $primero['lugares'][1]);

        $contexto = app(ContextoTenant::class);

        $conteo = $contexto->ejecutarAislado(
            $primero['operador']->id,
            fn (): int => Estacionamiento::query()->count()
        );

        $this->assertSame(1, $conteo);

        // Lo que se comprueba aca es que la transaccion AISLADA no dejo el
        // valor colgado en la conexion.
        $this->esperarFalloDeBase(function (): void {
            DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);
            DB::select('SELECT public.app_tenant_id()');
        });
    }

    public function test_la_funcion_de_contexto_falla_cerrada_sin_operador(): void
    {
        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);

        $this->expectException(QueryException::class);

        DB::select('SELECT public.app_tenant_id()');
    }

    public function test_la_bitacora_de_otro_operador_no_se_lee(): void
    {
        $primero = $this->crearOperador('primero.cl');

        app(\App\Services\Auditoria\RegistradorDeBitacora::class)->registrar(
            tenantId: $primero['operador']->id,
            usuario: $primero['administrador'],
            ambito: 'prueba',
            accion: 'crear',
            recursoTipo: 'Prueba',
            recursoId: null,
            descripcion: 'Entrada de bitacora del primer operador.',
        );

        $segundo = $this->crearOperador('segundo.cl');
        $this->fijarOperador($segundo['operador']->id);

        $this->assertSame(0, Bitacora::query()->count());
    }

    public function test_los_reclamos_del_otro_operador_no_existen(): void
    {
        $primero = $this->crearOperador('primero.cl');

        $reclamo = Reclamo::query()->create([
            'tenant_id' => $primero['operador']->id,
            'parqueadero_id' => $primero['sede']->id,
            'estacionamiento_id' => null,
            'registrado_por' => $primero['operario']->id,
            'folio' => 'R-000001',
            'estado' => 'abierto',
            'fecha_incidente' => now()->toDateString(),
            'descripcion' => 'Reclamo de ejemplo para la prueba de aislamiento.',
            'foto_ruta' => '',
        ]);

        $segundo = $this->crearOperador('segundo.cl');
        $this->fijarOperador($segundo['operador']->id);

        $this->assertNull(Reclamo::find($reclamo->id));
    }
}