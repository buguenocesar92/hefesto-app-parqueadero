<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Estacionamiento\CalculadoraTiempo;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * El tiempo que se cobra sale de las dos marcas de la base.
 *
 * Esta clase no toca la base de datos ni depende de HTTP, justamente para
 * poder probarla sola. Es el calculo que se importa mal con mas frecuencia:
 * una diferencia de un minuto se convierte en una diferencia de plata.
 *
 * NO SE EJECUTO. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class CalculadoraTiempoTest extends TestCase
{
    private CalculadoraTiempo $tiempo;

    protected function setUp(): void
    {
        $this->tiempo = new CalculadoraTiempo();
    }

    public function test_cuenta_los_segundos_entre_dos_marcas(): void
    {
        $ingreso = CarbonImmutable::parse('2026-03-10 08:00:00');
        $salida = CarbonImmutable::parse('2026-03-10 10:30:00');

        $this->assertSame(9000, $this->tiempo->segundos($ingreso, $salida));
    }

    public function test_una_salida_anterior_al_ingreso_es_un_error(): void
    {
        $ingreso = CarbonImmutable::parse('2026-03-10 10:00:00');
        $salida = CarbonImmutable::parse('2026-03-10 09:00:00');

        // Un tiempo negativo no se puede facturar. Lo de mas grave es que
        // daria un cobro negativo: el sistema terminaria devolviendo plata al
        // cliente.
        $this->expectException(InvalidArgumentException::class);

        $this->tiempo->segundos($ingreso, $salida);
    }

    public function test_entrar_y_salir_en_el_mismo_instante_cuesta_cero(): void
    {
        $instante = CarbonImmutable::parse('2026-03-10 08:00:00');

        $this->assertSame(0, $this->tiempo->segundos($instante, $instante));
    }

    public function test_el_desglose_es_para_mostrar_no_para_cobrar(): void
    {
        $desglose = $this->tiempo->desglosar(3725);

        $this->assertSame(1, $desglose['horas']);
        $this->assertSame(2, $desglose['minutos']);
        $this->assertSame(5, $desglose['segundos']);
        $this->assertSame('01:02:05', $desglose['texto']);
    }

    public function test_el_desglose_muestra_el_signo_de_un_tiempo_negativo(): void
    {
        // No deberia ocurrir en operacion: `segundos()` lo rechaza antes. Pero
        // si llegara un valor negativo desde otro lado, que se vea raro es
        // mejor que mostrar 02:05:00 como si fuera un tiempo positivo.
        $desglose = $this->tiempo->desglosar(-125);

        $this->assertSame('-00:02:05', $desglose['texto']);
    }

    public function test_un_ingreso_largo_que_atraviesa_medianoche(): void
    {
        // Un auto que entra el dia 10 a las 22:00 y sale el dia 11 a las
        // 02:00. Con marcas `timestamptz` esto es directo: la base guarda
        // UTC y no hay ambiguedad de zona horaria.
        $ingreso = CarbonImmutable::parse('2026-03-10 22:00:00');
        $salida = CarbonImmutable::parse('2026-03-11 02:00:00');

        $this->assertSame(14400, $this->tiempo->segundos($ingreso, $salida));
    }

    public function test_la_cortesia_se_resta_antes_de_redondear(): void
    {
        // 12 minutos (720 segundos) con 10 de cortesia: quedan 2 minutos netos.
        // Si se redondeara antes de restar, 12 redondeados a 15 menos 10 darian
        // 5, y se cobraria de mas.
        //
        // OJO: el primer argumento son SEGUNDOS, no minutos. La unidad es lo
        // unico que hay que tener presente al llamar a este metodo.
        $netos = $this->tiempo->minutosFacturables(720, 10, 1);

        $this->assertSame(2, $netos);
    }

    public function test_el_redondeo_es_hacia_arriba(): void
    {
        // 21 minutos (1260 segundos) con redondeo de 20: se cobran 40, no 20.
        // Redondear hacia abajo dejaria de cobrar un minuto real al cliente.
        $netos = $this->tiempo->minutosFacturables(1260, 0, 20);

        $this->assertSame(40, $netos);
    }

    public function test_la_cortesia_no_puede_dejar_el_cobro_en_negativo(): void
    {
        // 3 minutos con 10 de cortesia: no se cobra nada, no se devuelve nada.
        $netos = $this->tiempo->minutosFacturables(180, 10, 1);

        $this->assertSame(0, $netos);
    }

    public function test_el_redondeo_debe_ser_de_un_minuto_o_mas(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Un redondeo de cero haria que el calculo no tuviera multiplicador.
        $this->tiempo->minutosFacturables(1500, 0, 0);
    }
}