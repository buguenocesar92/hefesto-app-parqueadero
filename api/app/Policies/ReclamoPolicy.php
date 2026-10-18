<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reclamo;
use App\Models\Usuario;

/**
 * Reclamos: dondo se junta el dato personal de los clientes.
 *
 * Un reclamo contiene nombre, RUT, telefono, la foto del incidente y el
 * relato del cliente. Es el unico lugar de este sistema donde se juntan datos
 * personales de alguien que no es el usuario del sistema.
 *
 * POR QUE EL ROL DE LECTURA NO PUEDE VER TODO
 * -------------------------------------------
 * Quien solo tiene `lectura` puede ver el listado para atender un reclamo
 * comun, pero NO la ficha completa con el dato del cliente ni la foto. La
 * ficha completa es lo que se necesita para responder un reclamo, y lo que
 * hay que proteger si alguien copia la base.
 *
 * Registrar un reclamo lo puede cualquiera que opere: el mostrador es donde
 * llega el cliente y no puede mandarlo a otra parte.
 *
 * Resolverlo es otra cosa. Un reclamo resuelto es el fin de una
 * investigacion, y cerrarlo es una decision, no un trámite.
 */
class ReclamoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar() || $usuario->rol->puedeAdministrar();
    }

    /** El listado: folio, fecha, estado. Sin dato personal. */
    public function view(Usuario $usuario, Reclamo $reclamo): bool
    {
        return $this->viewAny($usuario);
    }

    /** La ficha completa, con nombre, RUT, contacto y foto. */
    public function verFichaCompleta(Usuario $usuario, Reclamo $reclamo): bool
    {
        return $usuario->rol->puedeOperar() || $usuario->rol->puedeAdministrar();
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar();
    }

    /** Cambiar el estado de un reclamo es parte del mostrador. */
    public function cambiarEstado(Usuario $usuario, Reclamo $reclamo): bool
    {
        return $usuario->rol->puedeOperar();
    }

    /**
     * Cerrar el reclamo con su resolucion escrita es una decision.
     * Requiere supervision o administracion.
     */
    public function resolver(Usuario $usuario, Reclamo $reclamo): bool
    {
        return $usuario->rol->puedeAdministrar() || $this->esSupervision($usuario);
    }

    private function esSupervision(Usuario $usuario): bool
    {
        return $usuario->rol->value === 'supervisor';
    }
}