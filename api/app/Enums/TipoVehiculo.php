<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de vehiculo.
 *
 * El tipo importa porque la tarifa puede ser distinta para motos que para
 * camiones. El texto de la etiqueta es el que ve el operario en la pantalla.
 */
enum TipoVehiculo: string
{
    case Automobile = 'automobile';
    case Camioneta = 'camioneta';
    case Moto = 'moto';
    case Camion = 'camion';
    case Furgon = 'furgon';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Automobile => 'Automovil',
            self::Camioneta => 'Camioneta',
            self::Moto => 'Moto',
            self::Camion => 'Camion',
            self::Furgon => 'Furgon',
        };
    }

    /** Un vehiculo pesado ocupa mas de un lugar en la practica del operador. */
    public function ocupaMasDeUnLugar(): bool
    {
        return $this === self::Camion;
    }
}
