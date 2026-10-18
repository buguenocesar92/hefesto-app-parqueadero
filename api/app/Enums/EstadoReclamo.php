<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados de un reclamo.
 *
 * Un reclamo no se "cierra": se resuelve o se rechaza, y en los dos casos
 * queda la explicacion escrita. Un reclamo sin resolucion es un reclamo que
 * sigue abierto, por mas dias que lleve en la lista.
 */
enum EstadoReclamo: string
{
    case Abierto = 'abierto';
    case EnRevision = 'en_revision';
    case Resuelto = 'resuelto';
    case Rechazado = 'rechazado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::EnRevision => 'En revision',
            self::Resuelto => 'Resuelto',
            self::Rechazado => 'Rechazado',
        };
    }

    public function estaCerrado(): bool
    {
        return in_array($this, [self::Resuelto, self::Rechazado], true);
    }

    /** Estados desde los que se puede pasar a este. */
    public function puedeIrDesde(self $actual): bool
    {
        if ($this === $actual) {
            return true;
        }

        return match ($actual) {
            self::Abierto => in_array($this, [self::EnRevision, self::Resuelto, self::Rechazado], true),
            self::EnRevision => in_array($this, [self::Resuelto, self::Rechazado], true),
            // Desde resuelto o rechazado no se vuelve atras: el reclamo se
            // cerro con una explicacion y reabrirlo sin explicacion borra
            // la cuenta de lo que el operador respondio.
            self::Resuelto, self::Rechazado => false,
        };
    }
}
