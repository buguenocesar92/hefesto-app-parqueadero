<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tarifa;
use App\Models\Usuario;

/**
 * Tarifas: el precio.
 *
 * El precio es lo unico que todos ven y lo unico que todos opinan. El riesgo
 * real no es que alguien mire la tarifa: es que alguien la cambie.
 *
 * QUE SE PUEDE CAMBIAR Y QUE NO
 * -----------------------------
 * Crear una tarifa nueva con vigencia desde manana es la operacion normal de
 * un aumento de precio, y la puede hacer el supervisor. Editar una tarifa que
 * ya se cobro NO: esa tarifa ya produjo boletas, y cambiar el valor de una
 * tarifa en uso reescribe la historia de las cuentas que se emitieron con
 * ella.
 *
 * Por eso el metodo se llama `crearVigencia` y no `update`. La tarifa vieja
 * no se toca: se le pone `vigente_hasta` y se crea la nueva.
 *
 * Desactivar es urgente: se hace cuando una tarifa se cargo mal y esta
 * produciendo cuentas que no corresponden.
 */
class TarifaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar() || $usuario->rol->puedeAdministrar();
    }

    public function view(Usuario $usuario, Tarifa $tarifa): bool
    {
        return $this->viewAny($usuario);
    }

    /** Dar de alta una tarifa con vigencia futura. */
    public function crear(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrar() || $usuario->rol->value === 'supervisor';
    }

    /**
     * Editar una tarifa.
     *
     * El criterio es SOLO la vigencia, y es suficiente por construccion: si
     * `vigente_desde` esta en el futuro, todavia no puede existir ninguna
     * boleta cobrada con esta tarifa, porque una boleta exige una sesion que
     * ocurrio despues de esa fecha. No hace falta preguntar a la base de
     * datos si ya se uso.
     *
     * Si la tarifa ya entro en vigencia, se corrige creando una tarifa nueva
     * y cerrando la anterior con `vigente_hasta`. Editar el valor de una
     * tarifa que ya cobro reescribe la historia de las cuentas que se
     * emitieron con ella.
     */
    public function update(Usuario $usuario, Tarifa $tarifa): bool
    {
        if ($usuario->rol->puedeAdministrar()) {
            return true;
        }

        return $usuario->rol->value === 'supervisor'
            && $tarifa->vigente_desde->isFuture();
    }

    public function desactivar(Usuario $usuario, Tarifa $tarifa): bool
    {
        return $usuario->rol->puedeAdministrar();
    }
}