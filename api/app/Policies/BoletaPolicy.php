<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Boleta;
use App\Models\Usuario;

/**
 * Boletas: documento de dinero.
 *
 * Cualquiera que opere puede VERLAS y reimprimirlas: sin eso el mostrador
 * no puede entregar el ticket a un cliente que lo perdio.
 *
 * Pero anular es otra cosa. Una boleta anulada es plata que dejo de
 * cobrar, y anular es el camino por el que un operador desviaria caja sin
 * que la fila desaparezca. Por eso anular queda arriba del todo y exige un
 * motivo escrito que queda en la boleta.
 *
 * El detalle de la boleta SI sale del operador: por el mismo motivo, ver la
 * sesion completa de un vehiculo y su cuenta revela el historial de todos los
 * clientes que usaron esa patente.
 */
class BoletaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar() || $usuario->rol->puedeAdministrar();
    }

    public function view(Usuario $usuario, Boleta $boleta): bool
    {
        return $this->viewAny($usuario);
    }

    /** El rol de lectura puede mirar el detalle para una consulta. */
    public function verDetalle(Usuario $usuario, Boleta $boleta): bool
    {
        return $this->viewAny($usuario);
    }

    /**
     * El mostrador emite: eso es la operacion de todos los dias.
     * La tarifa y el descuento los deja fijados el administrador.
     */
    public function emitir(Usuario $usuario): bool
    {
        return $usuario->rol->puedeOperar();
    }

    /**
     * Anular una boleta es el acto mas sensible del sistema: cambia el total
     * cobrado por el operador. Solo administracion.
     */
    public function anular(Usuario $usuario, Boleta $boleta): bool
    {
        return $usuario->rol->puedeAdministrar();
    }
}