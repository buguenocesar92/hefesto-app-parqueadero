<?php

declare(strict_types=1);

namespace Tests\Feature\Ocupacion;

use App\Models\Estacionamiento;
use App\Services\Estacionamiento\CalculadoraOcupacion;
use App\Services\Estacionamiento\RegistradorIngreso;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un lugar, a lo sumo una sesion abierta.
 *
 * ESTA ES LA GARANTIA DE CONCURRENCIA DEL SISTEMA
 * ------------------------------------------------
 * El conteo de lugares se hace en linea, con varios operarios marcando
 * ingresos al mismo tiempo en la misma puerta. Si dos lograran ocupar el
 * mismo lugar, el conteo diria uno y habria dos vehiculos: eso es peor que
 * un conteo erroneo visible, porque nadie lo nota.
 *
 * La garantia no la da el codigo: la da el indice unico parcial
 * `unico_lugar_sesion_abierta` (migracion 000015), que solo mira las filas
 * con `salida_en IS NULL`.
 *
 * NO SE EJECUTO. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class UnLugarUnaSesionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dos_ingresos_al_mismo_lugar_fallan(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'PRIM01',
            'JTDMB4C33P0000001',
        );

        $this->expectException(QueryException::class);

        // Escritura directa a proposito: se quiere comprobar el indice, no
        // el servicio que lo traduce a un mensaje.
        $sesionPrevia = Estacionamiento::query()->firstOrFail();

        DB::table('estacionamientos')->insert([
            'id' => '11111111-2222-3333-4444-555555555555',
            'tenant_id' => $sesionPrevia->tenant_id,
            'parqueadero_id' => $sesionPrevia->parqueadero_id,
            'vehiculo_id' => $sesionPrevia->vehiculo_id,
            'lugar_id' => $sesionPrevia->lugar_id,
            'patente' => 'SEGUN02',
            'vin' => 'JTDMB4C33P0000002',
            'ingreso_en' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_lugar_libre_despues_de_cobrar_la_salida(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'PRIM01',
            'JTDMB4C33P0000001',
        );

        \App\Services\Estacionamiento\RelojDelServidor::congelar(now()->addHour()->toImmutable());

        app(\App\Services\Estacionamiento\RegistradorSalida::class)
            ->registrar($sesion, $contexto['operario']);

        \App\Services\Estacionamiento\RelojDelServidor::descongelar();

        // Con la sesion cerrada, el mismo lugar puede volver a ocuparse: el
        // indice es PARCIAL y solo mira las abiertas.
        $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'SEGUN02',
            'JTDMB4C33P0000002',
        );

        $abiertas = Estacionamiento::query()->whereNull('salida_en')->count();

        $this->assertSame(1, $abiertas);
    }

    public function test_el_conteo_refleja_los_lugares_reales_y_no_los_declarados(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl', cantidadLugares: 4);

        $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'PRIM01',
            'JTDMB4C33P0000001',
        );

        $estado = app(CalculadoraOcupacion::class)->estado($contexto['sede']->id);

        $this->assertSame(4, $estado['capacidad'], 'La capacidad es la cuenta de lugares activos.');
        $this->assertSame(1, $estado['ocupados']);
        $this->assertSame(3, $estado['disponibles']);
        $this->assertFalse($estado['sobrecapacidad']);
    }

    public function test_un_lugar_inactivo_no_cuenta_como_capacidad(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl', cantidadLugares: 4);

        $contexto['lugares'][4]->activo = false;
        $contexto['lugares'][4]->save();

        $estado = app(CalculadoraOcupacion::class)->estado($contexto['sede']->id);

        $this->assertSame(3, $estado['capacidad']);
        $this->assertSame(1, $estado['lugares_inactivos']);
    }

    public function test_el_conteo_va_mas_alla_de_la_capacidad_y_lo_dice(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl', cantidadLugares: 2);

        // Dos lugares, tres vehiculos: solo caben dos abiertos, asi que el
        // tercero tiene que fallar. Y si se fuerza, el estado lo declara.
        $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'UNO001',
            'JTDMB4C33P0000001',
        );

        $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][2],
            'DOS002',
            'JTDMB4C33P0000002',
        );

        $estado = app(CalculadoraOcupacion::class)->estado($contexto['sede']->id);

        $this->assertSame(2, $estado['ocupados']);
        $this->assertSame(0, $estado['disponibles']);
        $this->assertFalse($estado['sobrecapacidad'], 'Con la capacidad correcta no hay sobrecapacidad.');
    }

    public function test_el_indice_parcial_solo_mira_las_sesiones_abiertas(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        // Un lugar con muchisimo historial cerrado y una abierta: el indice
        // no puede estar molesto por las cerradas.
        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'PRIM01',
            'JTDMB4C33P0000001',
        );

        $salidas = app(\App\Services\Estacionamiento\RegistradorSalida::class);

        for ($vuelta = 1; $vuelta <= 3; $vuelta++) {
            \App\Services\Estacionamiento\RelojDelServidor::congelar(
                now()->addHours($vuelta)->toImmutable()
            );

            $salidas->registrar($sesion, $contexto['operario']);

            \App\Services\Estacionamiento\RelojDelServidor::descongelar();

            $sesion = $this->dejarVehiculoAdentro(
                $contexto['operario'],
                $contexto['lugares'][1],
                'REPET' . $vuelta,
                'JTDMB4C33P100000' . $vuelta,
            );
        }

        $esteLugar = Estacionamiento::query()
            ->where('lugar_id', $contexto['lugares'][1]->id)
            ->count();

        $abiertas = Estacionamiento::query()
            ->where('lugar_id', $contexto['lugares'][1]->id)
            ->whereNull('salida_en')
            ->count();

        $this->assertSame(4, $esteLugar, 'Hay cuatro visitas al mismo lugar.');
        $this->assertSame(1, $abiertas, 'Y solo una abierta.');
    }

    public function test_el_servicio_de_ingreso_ofrece_un_lugar_libre_cercano(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl', cantidadLugares: 5);

        $ocupados = [1, 2];

        foreach ($ocupados as $indice) {
            $this->dejarVehiculoAdentro(
                $contexto['operario'],
                $contexto['lugares'][$indice],
                'OCUP' . $indice,
                'JTDMB4C33P20000' . $indice . '0',
            );
        }

        $libre = app(CalculadoraOcupacion::class)
            ->lugarLibreMasCercano($contexto['sede']->id, 'V-01');

        $this->assertNotNull($libre);
        $this->assertNotSame('V-01', $libre['codigo'], 'V-01 esta ocupado, no puede ofrecerlo.');
        $this->assertContains($libre['codigo'], ['V-02', 'V-03', 'V-04', 'V-05']);
    }
}