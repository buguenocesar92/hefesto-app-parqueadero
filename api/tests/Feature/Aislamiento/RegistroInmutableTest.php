<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Movimiento;
use App\Services\Estacionamiento\RegistradorIngreso;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El registro no se edita, y esta vez lo impone la base.
 *
 * POR QUE ESTA PRUEBA VA EN SUITE AISLAMIENTO Y NO EN UNA SUITE SUYA
 * -------------------------------------------------------------------
 * Porque es la misma idea: la garantia no esta en el codigo, esta en
 * PostgreSQL. La migracion 2026_10_17_000017 levanta el gatillo
 * `gatillo_movimientos_solo_insertar`.
 *
 * LO QUE SE COMPRUEBA
 * -------------------
 *  1. Un `UPDATE` sobre `movimientos` es rechazado por la base.
 *  2. Un `DELETE` sobre `movimientos` es rechazado por la base.
 *  3. Un `INSERT` SI se puede: el registro crece, que es lo correcto.
 *  4. Las anotaciones SI se pueden agregar, que es el camino previsto para
 *     "corregir" algo sin tocar el hecho.
 *
 * Sin esto, la promesse del sistema de que el registro es inmutable seria
 * solo una convencion, y una convencion se rompe con un `psql`.
 *
 * NO SE EJECUTO. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class RegistroInmutableTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_update_sobre_el_registro_es_rechazado(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');
        $this->dejarVehiculoAdentro($contexto['operario'], $contexto['lugares'][1]);

        $movimiento = Movimiento::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('movimientos')->where('id', $movimiento->id)->update([
            'patente' => 'OTRA99',
        ]);
    }

    public function test_un_delete_sobre_el_registro_es_rechazado(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');
        $this->dejarVehiculoAdentro($contexto['operario'], $contexto['lugares'][1]);

        $movimiento = Movimiento::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('movimientos')->where('id', $movimiento->id)->delete();
    }

    public function test_insertar_un_movimiento_nuevo_si_se_puede(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');
        $this->dejarVehiculoAdentro($contexto['operario'], $contexto['lugares'][1]);

        $sesion = \App\Models\Estacionamiento::query()->firstOrFail();

        $nuevo = Movimiento::query()->create([
            'tenant_id' => $contexto['operador']->id,
            'parqueadero_id' => $sesion->parqueadero_id,
            'estacionamiento_id' => $sesion->id,
            'operador_id' => $contexto['administrador']->id,
            'tipo' => 'anulacion',
            'ocurrido_en' => now(),
            'patente' => $sesion->patente,
            'vin' => $sesion->vin,
            'tipo_vehiculo' => 'automobile',
            'lugar_codigo' => $contexto['lugares'][1]->codigo,
        ]);

        $this->assertSame('anulacion', $nuevo->tipo->value);
    }

    public function test_agregar_una_anotacion_al_registro_si_se_puede(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');
        $this->dejarVehiculoAdentro($contexto['operario'], $contexto['lugares'][1]);

        $movimiento = Movimiento::query()->firstOrFail();

        $anotacion = \App\Models\AnotacionMovimiento::query()->create([
            'tenant_id' => $contexto['operador']->id,
            'movimiento_id' => $movimiento->id,
            'usuario_id' => $contexto['operario']->id,
            'texto' => 'El porton estaba cerrado con llave; el vehiculo entro por el acceso lateral.',
            'creado_en' => now(),
        ]);

        $this->assertSame($movimiento->id, $anotacion->movimiento_id);
        $this->assertSame(1, $movimiento->anotaciones()->count());
    }

    public function test_el_registro_no_se_puede_cambiar_tampoco_desde_eloquent(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');
        $this->dejarVehiculoAdentro($contexto['operario'], $contexto['lugares'][1]);

        $movimiento = Movimiento::query()->firstOrFail();

        $this->expectException(QueryException::class);

        $movimiento->patente = 'CAMBIADA';
        $movimiento->save();
    }

    public function test_un_ingreso_siempre_deja_su_movimiento(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');
        $sesion = $this->dejarVehiculoAdentro($contexto['operario'], $contexto['lugares'][1]);

        $movimientos = Movimiento::query()
            ->where('estacionamiento_id', $sesion->id)
            ->get();

        $this->assertCount(1, $movimientos, 'Un ingreso debe dejar exactamente un movimiento de ingreso.');
        $this->assertTrue($movimientos->first()->tipo->esIngreso());
    }

    public function test_el_movimiento_guarda_la_placa_tal_como_se_veia(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        // La placa con guion, como la escribe el operario.
        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'ab-12-34',
        );

        $movimiento = Movimiento::query()
            ->where('estacionamiento_id', $sesion->id)
            ->firstOrFail();

        // El servicio normaliza a mayusculas y sin signos.
        $this->assertSame('AB1234', $movimiento->patente);
        $this->assertSame('AB1234', $sesion->patente);
    }

    public function test_el_servicio_de_ingreso_traduce_el_choque_de_lugar(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        // Otro vehiculo toma el lugar primero.
        $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'PRIM01',
            'JTDMB4C33P0000001',
        );

        $this->expectException(\RuntimeException::class);

        app(RegistradorIngreso::class)->registrar($contexto['operario'], [
            'patente' => 'SEGUN02',
            'vin' => 'JTDMB4C33P0000002',
            'tipo_vehiculo' => 'automobile',
            'lugar_id' => $contexto['lugares'][1]->id,
        ]);
    }
}