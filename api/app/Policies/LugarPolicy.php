<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lugar;
use App\Models\Usuario;

/**
 * Lugares: el mapa fisico de la sede.
 *
 * Ver el mapa es trabajo de todos: el mostrador mira que lugares hay antes de
 * dejar entrar un vehiculo.
 *
 * Crear y desactivar lugares no es trivial, y la razon es concreta: un lugar
 * mas es capacidad que el operador va a prometer. Si `lugares` dice 120 y el
 * operador tiene 120 puestos reales, esta bien. Si la pantalla dice 120 y
 * hay 100, el reclamo aparece la primera vez que este lleno.
 *
 * POR QUE DESACTIVAR ES MAS RIGUROSO QUE CREAR
 * ---------------------------------------------
 * Crear un lugar es una decision reversible. Desactivar uno que tiene
 * vehiculos adentro deja esos vehiculos apuntando a un lugar que ya no
 * existe para el operador, y el conteo de ocupacion deja de cuadrar.
 * Por eso desactivar exige que este vacio.
 */
class LugarPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar()
            || $usuario->rol->puedeAdministrar()
            || $usuario->rol->value === 'lectura';
    }

    public function view(Usuario $usuario, Lugar $lugar): bool
    {
        return $this->viewAny($usuario);
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrar() || $usuario->rol->value === 'supervisor';
    }

    public function update(Usuario $usuario, Lugar $lugar): bool
    {
        return $usuario->rol->puedeAdministrar() || $usuario->rol->value === 'supervisor';
    }

    public function desactivar(Usuario $usuario, Lugar $lugar): bool
    {
        return $usuario->rol->puedeAdministrar();
    }
}