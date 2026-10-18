<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mensualidad;
use App\Models\Usuario;

/**
 * Mensualidades: acuerdo comercial y dato personal del cliente.
 *
 * Ver el listado (patente, nombre, estado, hasta cuando vence) es parte del
 * mostrador. Ver el RUT, el correo y el telefono de un titular es otra cosa:
 * no hace falta para ningun operativo del dia a dia y si son los datos que
 * un tercero busca cuando pide la base.
 *
 * COBRAR ES LO SENSIBLE
 * ---------------------
 * Registrar un pago cambia cuando se renovo la credencial, y con eso vuelve
 * el acceso de un vehiculo. Por eso no lo puede hacer quien solo lee.
 *
 * Cambiar el monto o las fechas del contrato es una renegociacion: solo
 * administracion.
 *
 * NADA QUE ESTE VENCIDO SE MODIFICA EN SILENCIO
 * ---------------------------------------------
 * Terminar una mensualidad tiene que ser una accion explicita con su
 * motivo en la bitacora, no un efecto colateral de que paso la fecha.
 */
class MensualidadPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar() || $usuario->rol->puedeAdministrar();
    }

    /** El listado sin el dato de contacto del titular. */
    public function view(Usuario $usuario, Mensualidad $mensualidad): bool
    {
        return $this->viewAny($usuario);
    }

    /** Con RUT, correo y telefono del titular. */
    public function verContacto(Usuario $usuario, Mensualidad $mensualidad): bool
    {
        return $usuario->rol->puedeAdministrar() || $usuario->rol->value === 'supervisor';
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrar() || $usuario->rol->value === 'supervisor';
    }

    /**
     * Registrar un pago: mueve plata y devuelve el acceso de un vehiculo.
     * Lo puede hacer quien cobra, que es el mostrador.
     */
    public function registrarPago(Usuario $usuario, Mensualidad $mensualidad): bool
    {
        return $usuario->rol->puedeCobrar();
    }

    /** Renegociar montos o fechas es una decision de administracion. */
    public function update(Usuario $usuario, Mensualidad $mensualidad): bool
    {
        return $usuario->rol->puedeAdministrar();
    }

    /** Terminar el contrato. */
    public function terminar(Usuario $usuario, Mensualidad $mensualidad): bool
    {
        return $usuario->rol->puedeAdministrar();
    }
}