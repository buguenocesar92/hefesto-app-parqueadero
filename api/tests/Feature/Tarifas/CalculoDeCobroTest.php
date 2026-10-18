<?php

declare(strict_types=1);

namespace Tests\Feature\Tarifas;

use App\Models\Boleta;
use App\Models\Estacionamiento;
use App\Models\Tarifa;
use App\Services\Boletas\GeneradorDeBoleta;
use App\Services\Boletas\ReconstructorDeBoleta;
use App\Services\Estacionamiento\CalculadoraTarifa;
use App\Services\Estacionamiento\RegistradorIngreso;
use App\Services\Estacionamiento\RegistradorSalida;
use App\Services\Estacionamiento\RelojDelServidor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * El cobro: la parte del sistema donde un error es plata.
 *
 * LOS CASOS QUE IMPORTAN
 * ----------------------
 *  - la cortesia se descuenta antes de contar fracciones;
 *  - una fraccion incompleta se cobra como fraccion completa, no como una
 *    parte proporcional: por eso el calculo usa `ceil` sobre SEGUNDOS;
 *  - un auto que se pasa del dia no se cobra el doble por un error de
 *    redondeo;
 *  - la tarifa que se aplica es la del DIA DEL INGRESO, no la de hoy;
 *  - el total del dia se arma en la base, no sumando filas en PHP.
 *
 * NO SE EJECUTO. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class CalculoDeCobroTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tarifa de ejemplo: fraccion de 20 minutos a $500, con 10 de cortesia.
     */
    private function tarifaPorTiempo(int $valor = 500, int $minutos = 20, int $cortesia = 10): Tarifa
    {
        return new Tarifa([
            'tipo' => Tarifa::TIPO_POR_TIEMPO,
            'valor' => $valor,
            'minutos_por_fraccion' => $minutos,
            'minutos_incluidos' => $cortesia,
            'nombre' => 'Fraccion de prueba',
        ]);
    }

    public function test_dentro_de_la_cortesia_no_se_cobra_nada(): void
    {
        // 10 minutos con 10 de cortesia: 0 fracciones, $0.
        $calculo = app(CalculadoraTarifa::class)->calcular($this->tarifaPorTiempo(), 600);

        $this->assertSame(0, $calculo['subtotal']);
        $this->assertSame(0, $calculo['detalle']['fracciones']);
    }

    public function test_una_fraccion_exacta_se_cobra_una_vez(): void
    {
        // 30 minutos, 10 de cortesia: 20 minutos netos = 1 fraccion de 20.
        $calculo = app(CalculadoraTarifa::class)->calcular($this->tarifaPorTiempo(), 1800);

        $this->assertSame(1, $calculo['detalle']['fracciones']);
        $this->assertSame(500, $calculo['subtotal']);
    }

    public function test_una_fraccion_incompleta_se_cobra_como_fraccion_completa(): void
    {
        // 20 minutos exactos: 1 fraccion. 21 minutos: 2 fracciones. El cobro
        // es por fraccion empezada, y por eso el calculo cuenta con `ceil`.
        $calculo = app(CalculadoraTarifa::class)->calcular($this->tarifaPorTiempo(), 1260);

        $this->assertSame(2, $calculo['detalle']['fracciones']);
        $this->assertSame(1000, $calculo['subtotal']);
    }

    public function test_un_segundo_sobre_la_fraccion_alza_la_fraccion(): void
    {
        // 20 minutos y 1 segundo. El detalle esta en `segundos_cobrables`:
        // el cobro se hace en segundos para que el instante no se pierda.
        $calculo = app(CalculadoraTarifa::class)->calcular($this->tarifaPorTiempo(), 1201);

        $this->assertSame(2, $calculo['detalle']['fracciones']);
    }

    public function test_la_cortesia_no_puede_producir_un_cobro_negativo(): void
    {
        // 3 minutos con 10 de cortesia: no se cobra nada, no se "descuenta".
        $calculo = app(CalculadoraTarifa::class)->calcular($this->tarifaPorTiempo(), 180);

        $this->assertSame(0, $calculo['subtotal']);
    }

    public function test_un_tarifa_por_evento_no_depende_del_tiempo(): void
    {
        $tarifa = new Tarifa([
            'tipo' => Tarifa::TIPO_POR_EVENTO,
            'valor' => 2000,
            'minutos_por_fraccion' => null,
            'minutos_incluidos' => 0,
            'nombre' => 'Carga y descarga',
        ]);

        $corto = app(CalculadoraTarifa::class)->calcular($tarifa, 300);
        $largo = app(CalculadoraTarifa::class)->calcular($tarifa, 72000);

        $this->assertSame(2000, $corto['subtotal']);
        $this->assertSame(2000, $largo['subtotal'], 'El precio fijo no depende de las horas.');
    }

    public function test_una_tarifa_sin_minutos_por_fraccion_no_se_puede_cobrar(): void
    {
        $this->expectException(RuntimeException::class);

        app(CalculadoraTarifa::class)->calcular($this->tarifaPorTiempo(minutos: 0), 600);
    }

    public function test_un_tipo_de_tarifa_desconocido_falla_en_vez_de_cobrar_cero(): void
    {
        // Es mejor que el cobro falle aqui, en la pantalla del operador, que
        // no en la barrera con un cliente esperando.
        $this->expectException(RuntimeException::class);

        app(CalculadoraTarifa::class)->calcular(
            new Tarifa([
                'tipo' => 'por_hora',
                'valor' => 1000,
                'minutos_por_fraccion' => 60,
                'minutos_incluidos' => 0,
                'nombre' => 'Tipo que el calculo no conoce',
            ]),
            600
        );
    }

    public function test_se_cobra_la_tarifa_del_dia_del_ingreso_y_no_la_de_hoy(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        // Se da de alta una tarifa VIEJA, que es la que rigo hace un mes.
        Tarifa::query()->create([
            'tenant_id' => $contexto['operador']->id,
            'parqueadero_id' => $contexto['sede']->id,
            'nombre' => 'Tarifa anterior',
            'tipo' => Tarifa::TIPO_POR_TIEMPO,
            'valor' => 400,
            'minutos_por_fraccion' => 20,
            'minutos_incluidos' => 0,
            'vigente_desde' => now()->subYear(),
            'vigente_hasta' => now()->subDays(1),
            'activo' => false,
        ]);

        // El auto entra AYER, cuando rigia la tarifa de $400.
        RelojDelServidor::congelar(now()->subDay()->toImmutable());

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'VIEJO1',
            'JTDMB4C33P0000001',
        );

        // Sale hoy, cuando ya rige la tarifa de $500.
        RelojDelServidor::congelar(now()->toImmutable());

        $boleta = app(RegistradorSalida::class)->registrar($sesion, $contexto['operario']);

        RelojDelServidor::descongelar();

        // Siete horas de permanencia: sobre la tarifa de $400 con 0 minutos de
        // cortesia son 21 fracciones de 20 minutos, o $8.400. Sobre la de
        // $500 serian 21 fracciones de $500 = $10.500.
        $this->assertSame(8400, $boleta->total);
        $this->assertSame('Tarifa anterior', $boleta->desglose['tarifa']);
    }

    public function test_el_descuento_no_puede_superar_el_total(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'DESC01',
            'JTDMB4C33P0000002',
        );

        $this->expectException(RuntimeException::class);

        app(RegistradorSalida::class)->registrar($sesion, $contexto['operario'], [
            'forma_pago' => 'efectivo',
            'descuento' => 999999,
        ]);
    }

    public function test_el_descuento_se_resta_del_subtotal_y_no_lo_altera(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'DESC02',
            'JTDMB4C33P0000003',
        );

        RelojDelServidor::congelar(now()->addMinutes(40)->toImmutable());

        $boleta = app(RegistradorSalida::class)->registrar($sesion, $contexto['operario'], [
            'forma_pago' => 'efectivo',
            'descuento' => 500,
        ]);

        RelojDelServidor::descongelar();

        $this->assertSame($boleta->subtotal - 500, $boleta->total);
        $this->assertSame(500, $boleta->descuento);
    }

    public function test_no_se_cobra_dos_veces_el_mismo_estacionamiento(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'DOBLE1',
            'JTDMB4C33P0000004',
        );

        RelojDelServidor::congelar(now()->addHour()->toImmutable());

        $salida = app(RegistradorSalida::class);

        $salida->registrar($sesion, $contexto['operario']);

        $this->expectException(RuntimeException::class);

        // El segundo intento tiene que fallar: si no, el operador cobra dos
        // veces por el mismo auto.
        $salida->registrar($sesion->fresh(), $contexto['operario']);
    }

    public function test_la_boleta_guarda_el_desglose_que_explica_el_cobro(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'DESGL1',
            'JTDMB4C33P0000005',
        );

        RelojDelServidor::congelar(now()->addMinutes(35)->toImmutable());

        $boleta = app(RegistradorSalida::class)->registrar($sesion, $contexto['operario']);

        RelojDelServidor::descongelar();

        // Si la tarifa cambia manana, este detalle sigue explicando el cobro
        // de hoy. Por eso existe la columna `desglose`.
        $this->assertSame('por_tiempo', $boleta->desglose['metodo']);
        $this->assertSame(20, $boleta->desglose['minutos_por_fraccion']);
        $this->assertSame(500, $boleta->desglose['valor_por_fraccion']);
        $this->assertSame(10, $boleta->desglose['minutos_cortesia']);
        $this->assertArrayHasKey('fracciones', $boleta->desglose);
        $this->assertArrayHasKey('tiempo', $boleta->desglose);
    }

    public function test_la_boleta_queda_anulada_con_motivo_y_no_se_borra(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'ANULA1',
            'JTDMB4C33P0000006',
        );

        RelojDelServidor::congelar(now()->addMinutes(30)->toImmutable());

        $boleta = app(RegistradorSalida::class)->registrar($sesion, $contexto['operario']);

        RelojDelServidor::descongelar();

        $boleta->estado = Boleta::ESTADO_ANULADA;
        $boleta->anulacion_motivo = 'Se cobro dos veces por un doble marcado del porton.';
        $boleta->save();

        $anulada = Boleta::query()->find($boleta->id);

        // La fila sigue ahi, con el total que se cobro y el motivo escrito.
        // Anular no es borrar.
        $this->assertNotNull($anulada);
        $this->assertSame(Boleta::ESTADO_ANULADA, $anulada->estado);
        $this->assertSame($boleta->total, $anulada->total);
        $this->assertNotNull($anulada->anulacion_motivo);
    }

    public function test_el_total_del_dia_solo_suma_boletas_emitidas(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $ingreso = app(RegistradorIngreso::class);
        $salida = app(RegistradorSalida::class);

        // Dos boletas emitidas y una anulada.
        for ($i = 1; $i <= 3; $i++) {
            $sesion = $ingreso->registrar($contexto['operario'], [
                'patente' => sprintf('TOTA%02d', $i),
                'vin' => sprintf('JTDMB4C33P100000%d', $i),
                'tipo_vehiculo' => 'automobile',
                'lugar_id' => $contexto['lugares'][$i]->id,
            ]);

            $boleta = $salida->registrar($sesion, $contexto['operario']);

            if ($i === 3) {
                $boleta->estado = Boleta::ESTADO_ANULADA;
                $boleta->save();
            }
        }

        $total = app(CalculadoraTarifa::class)->totalDelDia(
            $contexto['sede']->id,
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString(),
        );

        RelojDelServidor::descongelar();

        $this->assertSame(2, $total['boletas'], 'La anulada no cuenta como boleta del dia.');
    }

    public function test_se_puede_reconstruir_la_boleta_de_un_ticket_perdido(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'TICKET',
            'JTDMB4C33P0000007',
        );

        RelojDelServidor::congelar(now()->addMinutes(25)->toImmutable());

        // Se cierra la sesion Y se emite la boleta, pero el cliente pierde el
        // papel. La sesion queda con su `segundos_permanencia` guardado.
        app(RegistradorSalida::class)->registrar($sesion, $contexto['operario']);

        RelojDelServidor::descongelar();

        $sesionCerrada = Estacionamiento::query()->findOrFail($sesion->id);

        // Para simular el ticket perdido hay que despegar la boleta. En la
        // aplicacion real eso no pasa: el caso real es cuando la boleta nunca
        // se emitio (una maquina que cayo a mitad de camino).
        $sesionCerrada->boleta_id = null;
        $sesionCerrada->save();

        $reconstruida = app(ReconstructorDeBoleta::class)->reconstruir(
            $sesionCerrada,
            $contexto['operario'],
        );

        $this->assertNotSame('', $reconstruida->folio);
        $this->assertSame(3600, $reconstruida->desglose['segundos_permanencia']);
        $this->assertSame(
            $sesionCerrada->id,
            Estacionamiento::query()->findOrFail($sesionCerrada->id)->boleta_id
        );
    }

    public function test_no_se_reconstruye_una_boleta_que_ya_existe(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'YAEXIS',
            'JTDMB4C33P0000008',
        );

        RelojDelServidor::congelar(now()->addMinutes(25)->toImmutable());

        app(RegistradorSalida::class)->registrar($sesion, $contexto['operario']);

        RelojDelServidor::descongelar();

        $this->expectException(RuntimeException::class);

        app(ReconstructorDeBoleta::class)->reconstruir(
            Estacionamiento::query()->findOrFail($sesion->id),
            $contexto['operario'],
        );
    }

    public function test_la_boleta_se_emite_una_sola_vez_por_folio(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $ingreso = app(RegistradorIngreso::class);
        $salida = app(RegistradorSalida::class);

        for ($i = 1; $i <= 3; $i++) {
            $sesion = $ingreso->registrar($contexto['operario'], [
                'patente' => sprintf('FOLIO%d', $i),
                'vin' => sprintf('JTDMB4C33P200000%d', $i),
                'tipo_vehiculo' => 'automobile',
                'lugar_id' => $contexto['lugares'][$i]->id,
            ]);

            $salida->registrar($sesion, $contexto['operario']);
        }

        RelojDelServidor::descongelar();

        $folios = Boleta::query()->pluck('folio')->all();

        $this->assertCount(3, $folios);
        $this->assertCount($folios, array_unique($folios), 'Los folios tienen que ser distintos.');
    }

    public function test_el_generador_de_boleta_calcula_con_la_tarifa_que_recibe(): void
    {
        $contexto = $this->crearOperador('ejemplo.cl');

        RelojDelServidor::congelar(now()->toImmutable());

        $sesion = $this->dejarVehiculoAdentro(
            $contexto['operario'],
            $contexto['lugares'][1],
            'DIRECTA',
            'JTDMB4C33P0000009',
        );

        RelojDelServidor::descongelar();

        $boleta = app(GeneradorDeBoleta::class)->emitir(
            sesion: $sesion,
            operador: $contexto['operario'],
            tarifa: $contexto['tarifa'],
            segundosPermanencia: 1800,
            formaPago: 'efectivo',
            descuento: 0,
        );

        // 30 minutos con 10 de cortesia: 1 fraccion de $500.
        $this->assertSame(500, $boleta->total);
        $this->assertSame('emitida', $boleta->estado);
    }
}