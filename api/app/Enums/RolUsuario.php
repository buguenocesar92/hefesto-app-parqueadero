<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles dentro de un operador.
 *
 * El aislamiento entre operadores lo hace la base de datos (RLS). Estos roles
 * son un segundo filtro, DENTRO del operador: que un operario de puerta
 * vea la pantalla de cobros no significa que deba ver la tarifa de otro
 * operador. Ver docs/03.
 */
enum RolUsuario: string
{
    /** Ve y opera todo del operador: ingresos, salidas, cobranza, ajustes. */
    case Administrador = 'administrador';

    /** Opera la puerta y ve la cobranza, pero no cambia configuracion. */
    case Supervisor = 'supervisor';

    /** Marca ingresos y salidas. Es el rol tipico de la caseta. */
    case Operario = 'operario';

    /** Solo mira. No registra nada. Para el dueño que quiere consultar. */
    case Lectura = 'lectura';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Administrador => 'Administrador',
            self::Supervisor => 'Supervisor',
            self::Operario => 'Operario',
            self::Lectura => 'Solo lectura',
        };
    }

    /** Puede registrar ingresos y salidas. */
    public function puedeOperar(): bool
    {
        return $this !== self::Lectura;
    }

    /** Puede cobrar y resolver reclamos. */
    public function puedeCobrar(): bool
    {
        return in_array($this, [self::Administrador, self::Supervisor], true);
    }

    /** Puede cambiar tarifas, lugares y mensualidades. */
    public function puedeAdministrar(): bool
    {
        return $this === self::Administrador;
    }
}
