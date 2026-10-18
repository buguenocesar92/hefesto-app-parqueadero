<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;

/**
 * Personas: el rol decide todo lo que se hace en el sistema.
 *
 * DOS REGLAS QUE NO SE ROMPEN
 * ---------------------------
 *
 *  1. **NADIE SE SUBA SU PROPIO ROL.** Quien puede cambiar roles no puede
 *     cambiar el suyo. Sin esto, el rol `lectura` no significaria nada: un
 *     operario con acceso a la pantalla de personas se haria administrador.
 *
 *  2. **SOLO ADMINISTRACION.** El supervisor conoce el negocio y el dinero
 *     del dia, pero la lista de personas con acceso a los datos de los
 *     clientes la maneja administracion.
 */
class UsuarioPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrar();
    }

    public function view(Usuario $usuario, Usuario $otro): bool
    {
        return $this->viewAny($usuario);
    }

    /** Crear el vinculo con una cuenta de Authentik: administracion. */
    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrar();
    }

    public function update(Usuario $usuario, Usuario $otro): bool
    {
        return $usuario->rol->puedeAdministrar();
    }

    /**
     * Cambiar el rol de alguien.
     *
     * La comparacion es sobre la fila objetivo, no sobre el rol: no importa
     * si el usuario objetivo es el mismo u otro.
     */
    public function cambiarRol(Usuario $usuario, Usuario $otro): bool
    {
        if ($usuario->rol->puedeAdministrar()) {
            return true;
        }

        return $usuario->rol->value === 'supervisor'
            && ! $usuario->is($otro);
    }

    /**
     * Dar de baja a una persona.
     *
     * Nunca se borra la fila: se marca `activo = false`. Los movimientos que
     * esa persona registro son parte del registro y tienen que seguir
     * atribuidos a alguien.
     */
    public function desactivar(Usuario $usuario, Usuario $otro): bool
    {
        return $usuario->rol->puedeAdministrar() && ! $usuario->is($otro);
    }
}