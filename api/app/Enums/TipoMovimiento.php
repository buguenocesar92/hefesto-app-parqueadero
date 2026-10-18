<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de movimiento del registro.
 *
 * `Ingreso` y `Salida` son los dos que maneja la aplicacion. `Anulacion`
 * existe para el caso en que un operario marco mal un ingreso: no se borra
 * el movimiento original, se agrega una anotacion que dice lo que paso. El
 * registro tiene que poder mostrar que se corrigio algo, no que nunca
 * ocurrio.
 */
enum TipoMovimiento: string
{
    case Ingreso = 'ingreso';
    case Salida = 'salida';
    case Anulacion = 'anulacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ingreso => 'Ingreso',
            self::Salida => 'Salida',
            self::Anulacion => 'Anulacion',
        };
    }

    public function esIngreso(): bool
    {
        return $this === self::Ingreso;
    }

    public function esSalida(): bool
    {
        return $this === self::Salida;
    }
}
