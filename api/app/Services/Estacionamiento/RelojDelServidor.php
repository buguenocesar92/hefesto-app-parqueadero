<?php

declare(strict_types=1);

namespace App\Services\Estacionamiento;

use Carbon\CarbonImmutable;

/**
 * La hora del servidor, en un solo lugar.
 *
 * TODO el tiempo del sistema sale de aca. Concentrarlo tiene una razon
 * concreta: el calculo del tiempo facturable es del servidor, nunca del
 * cliente. Si dos partes de la aplicacion tomaran la hora por su cuenta,
 * habria dos relojes que podrian discrepar por fracciones de segundo y el
 * resultado seria un cobro inexplicable.
 *
 * En las pruebas se repara con `RelojDelServidor::congelar()`, que fija el
 * instante y permite correr el tiempo de forma determinista. Sin eso, una
 * prueba de tiempo seria intermitente: pasaria en dias rapidos y fallaria
 * cuando el reloj cruza el segundo. Ver docs/10, seccion "Reloj del
 * servidor en las pruebas".
 */
class RelojDelServidor
{
    private static ?CarbonImmutable $instanteCongelado = null;

    /**
     * Ahora, en el instante congelado si lo hay.
     */
    public static function ahora(): CarbonImmutable
    {
        return self::$instanteCongelado ?? CarbonImmutable::now();
    }

    /**
     * Fija el reloj en un instante. Solo para pruebas.
     */
    public static function congelar(CarbonImmutable $instante): void
    {
        self::$instanteCongelado = $instante;
    }

    /**
     * Avanza el reloj congelado. Solo para pruebas.
     *
     * Permite escribir una prueba que diga "pasaron exactamente 63 minutos
     * y 7 segundos" sin depender de `sleep()`.
     */
    public static function avanzar(int $segundos): void
    {
        self::$instanteCongelado = self::ahora()->addSeconds($segundos);
    }

    /**
     * Devuelve el reloj al estado real.
     */
    public static function descongelar(): void
    {
        self::$instanteCongelado = null;
    }
}
