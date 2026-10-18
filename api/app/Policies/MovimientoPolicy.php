<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Movimiento;
use App\Models\Usuario;

/**
 * Movimientos: el registro. SOLO LECTURA.
 *
 * No existe `update` ni `delete` en esta Policy, a proposito. El registro no
 * se corrige: se anota al lado (ver `AnotacionMovimiento`) y, si el hecho
 * estaba mal, se escribe un movimiento de anulacion.
 *
 * La garantia no es solo esta clase: la migracion 000017 levanta un gatillo
 * en PostgreSQL que rechaza el `UPDATE` y el `DELETE` sobre `movimientos`.
 * Un `psql` con la contrasena de la base tampoco puede reescribir el
 * registro. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 *
 * Quien puede VER el registro: cualquiera que opere. Un reclamo se
 * investigates mirando movimientos, y el mostrador es quien lo escucha.
 * El rol de lectura tambien puede: su trabajo es revisar, y para revisar
 * tiene que poder leer.
 */
class MovimientoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar()
            || $usuario->rol->puedeAdministrar()
            || $usuario->rol->value === 'lectura';
    }

    public function view(Usuario $usuario, Movimiento $movimiento): bool
    {
        return $this->viewAny($usuario);
    }

    /**
     * Agregar una anotacion es lo unico que se permite hacer sobre el
     * registro. Es agregar al lado, nunca cambiar.
     */
    public function anotar(Usuario $usuario, Movimiento $movimiento): bool
    {
        return $this->viewAny($usuario);
    }

    /**
     * Anular un movimiento.
     *
     * Anular no borra: escribe un movimiento nuevo de tipo `anulacion`. Es la
     * unica forma de "deshacer", y por eso es lo mas delicado que se puede
     * hacer con el registro. Administracion unicamente.
     */
    public function anular(Usuario $usuario, Movimiento $movimiento): bool
    {
        return $usuario->rol->puedeAdministrar();
    }
}