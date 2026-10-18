<?php

declare(strict_types=1);

namespace App\Services\Estacionamiento;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Calculo del tiempo permanecido, en SEGUNDOS.
 *
 * POR QUE SEGUNDOS Y NO MINUTOS
 * -----------------------------
 * Un ticket de una hora y tres minutos tiene que cobrar lo que corresponde.
 * Si se redondea a minutos al calcular, un auto que estuvo 1 hora y 29
 * segundos se cobra igual que uno de 1 hora y 59 segundos, y un auto de
 * 1 hora y 31 segundos se cobra mas caro que uno de 2 horas 29 segundos
 * cuando la tarifa es por fraccion. El error se repite en miles de boletas.
 *
 * El tiempo crudo se guarda SIEMPRE en segundos y sin redondear, en
 * `estacionamientos.segundos_permanencia`. El redondeo a minutos ocurre
 * despues, y solo para cobrar. Esa separacion es lo que permite, mas tarde:
 *
 *  - reconstruir la cuenta desde el registro aunque la tarifa haya cambiado;
 *  - corregir la tarifa sin perder el tiempo real;
 *  - explicar una boleta de hace seis meses.
 *
 * DE DONDE VIENE EL TIEMPO
 * -----------------------
 * De las dos marcas de tiempo de la base: `ingreso_en` y `salida_en`, que
 * son columnas `timestamptz`. El reloj del cliente no participa: la API
 * acepta el instante como dato informado, pero el tiempo facturable se
 * calcula contra la hora de la base de datos. Ver
 * `RelojDelServidor::ahora()`.
 *
 * Es un servicio sin dependencias de HTTP ni de base de datos a proposito:
 * se puede probar solo, que es lo que hace
 * `tests/Unit/CalculadoraTiempoTest.php`.
 */
class CalculadoraTiempo
{
    /**
     * Segundos entre dos instantes.
     *
     * @param  CarbonImmutable  $ingreso  Momento de entrada.
     * @param  CarbonImmutable  $salida   Momento de salida.
     *
     * @throws InvalidArgumentException si la salida es anterior al ingreso.
     */
    public function segundos(CarbonImmutable $ingreso, CarbonImmutable $salida): int
    {
        if ($salida->getTimestamp() < $ingreso->getTimestamp()) {
            throw new InvalidArgumentException(
                'La salida no puede ser anterior al ingreso. '
                . 'Un tiempo negativo no se puede facturar.'
            );
        }

        return $salida->getTimestamp() - $ingreso->getTimestamp();
    }

    /**
     * Tiempo en segundos hasta el instante actual, para una sesion abierta.
     *
     * @param  CarbonImmutable  $ingreso
     */
    public function segundosHastaAhora(CarbonImmutable $ingreso): int
    {
        return $this->segundos($ingreso, RelojDelServidor::ahora());
    }

    /**
     * Descompone los segundos en horas, minutos y segundos, para mostrarlo.
     *
     * Es solo presentacion: el numero que se cobra se calcula en
     * `CalculadoraTarifa`, sobre los segundos.
     *
     * @return array{horas: int, minutos: int, segundos: int, texto: string}
     */
    public function desglosar(int $segundos): array
    {
        $negativo = $segundos < 0;
        $segundos = abs($segundos);

        $horas = intdiv($segundos, 3600);
        $minutos = intdiv($segundos % 3600, 60);
        $resto = $segundos % 60;

        $texto = sprintf('%02d:%02d:%02d', $horas, $minutos, $resto);

        return [
            'horas' => $horas,
            'minutos' => $minutos,
            'segundos' => $resto,
            'texto' => ($negativo ? '-' : '') . $texto,
        ];
    }

    /**
     * Minutos que se cobran, aplicando la cortesia y el redondeo.
     *
     * El redondeo es hacia arriba y a multiplos de `minutosRedondeo`, porque
     * redondear hacia abajo deja de cobrar un minuto real al cliente. La
     * cortesia se resta ANTES de redondear: si no, una estadia de 12 minutos
     * con 10 de cortesia redondearia a 10 y se cobraria de menos.
     *
     * @param  int  $minutosCortesia  Minutos que no se cobran.
     * @param  int  $minutosRedondeo  Multiplo de redondeo, 1 o mas.
     *
     * @throws InvalidArgumentException si `minutosRedondeo` es menor que 1.
     */
    public function minutosFacturables(
        int $segundos,
        int $minutosCortesia = 0,
        int $minutosRedondeo = 1
    ): int {
        if ($minutosRedondeo < 1) {
            throw new InvalidArgumentException(
                'El redondeo de minutos debe ser al menos 1. Con 0 la division no existe.'
            );
        }

        $minutosCortesia = max(0, $minutosCortesia);

        // La cortesia se mide en minutos completos, no en segundos: por
        // eso se redondea la diferencia hacia arriba antes de restarla.
        $minutosBrutos = (int) ceil($segundos / 60);

        $minutosNetos = max(0, $minutosBrutos - $minutosCortesia);

        return (int) ceil($minutosNetos / $minutosRedondeo) * $minutosRedondeo;
    }
}
