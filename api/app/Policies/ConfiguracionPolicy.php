<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Configuracion;
use App\Models\Usuario;

/**
 * Configuracion del operador: las reglas con las que se cobra y se deja
 * entrar.
 *
 * Estos valores cambian el resultado de una operacion de dinero y el acceso
 * de una persona. Que los pueda cambiar cualquiera que opere seria dejar en
 * manos del mostrador el precio y la cortesia. Solo administracion.
 *
 * Nota importante: cambiar `mensualidad_vencida_bloquea_acceso` NO cierra
 * ningun porton. El sistema avisa y marca el ingreso; la persona decide si
 * deja pasar. Ver docs/06-INGRESO-VEHICULO.md.
 */
class ConfiguracionPolicy
{
    public function view(Usuario $usuario, Configuracion $configuracion): bool
    {
        return $usuario->rol->puedeOperar()
            || $usuario->rol->puedeAdministrar()
            || $usuario->rol->value === 'supervisor';
    }

    public function update(Usuario $usuario, Configuracion $configuracion): bool
    {
        return $usuario->rol->puedeAdministrar();
    }
}