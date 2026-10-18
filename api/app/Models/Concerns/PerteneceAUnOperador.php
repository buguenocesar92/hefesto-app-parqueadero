<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Tenancy\ContextoTenant;

/**
 * Comportamiento comun de los modelos de negocio.
 *
 * `scopeDelOperadorActual()` existe por comodidad, no por seguridad: el
 * aislamiento real lo hace la RLS de PostgreSQL, que filtra por `tenant_id`
 * aunque la consulta no lo diga. Este scope evita escribirlas a mano, pero
 * si alguien lo olvida, no pasa nada: la base sigue filtrando.
 *
 * Que sea una comodidad y no la garantia es lo importante. Si se apoyara en
 * este scope para aislar, un `DB::table('boletas')` olvidado entregaria
 * datos de otro operador sin avisar.
 *
 * Ver docs/03, seccion "El aislamiento no puede depender solo del codigo".
 */
trait PerteneceAUnOperador
{
    /**
     * Filtra por el operador de la peticion.
     *
     * El valor se LEE de la variable de sesion `app.tenant_id`, no se compara
     * contra el nombre de la variable. Comparar contra el nombre seria
     * emparejar `tenant_id` con la cadena 'app.tenant_id', que no es un
     * identificador: la consulta no devolveria nada.
     *
     * Si no hay operador en contexto el valor es `null`, y `where` con
     * `null` no empareja ninguna fila. Es el criterio correcto para una
     * comodidad: sin contexto no se devuelve nada, en vez de devolver todo.
     * La garantia real sigue siendo la RLS, que ademas falla cerrada con
     * excepcion (ver docs/03).
     */
    public function scopeDelOperadorActual($consulta)
    {
        $contexto = new ContextoTenant();

        return $consulta->where(
            $this->getTable() . '.tenant_id',
            $contexto->tenantIdEnConexion()
        );
    }

    /**
     * Asigna el operador de la peticion al crear el modelo.
     *
     * Escribir el `tenant_id` a mano en cada `create()` es una occasion de
     * equivocarse. Aqui se toma del contexto que el middleware ya fijo, y
     * si no hay contexto, la escritura falla en la base de datos por la
     * politica `WITH CHECK`: no se puede guardar una fila en nombre de otro
     * operador.
     */
    public static function bootPerteneceAUnOperador(): void
    {
        static::creating(function ($modelo): void {
            if ($modelo->tenant_id !== null) {
                return;
            }

            $contexto = new ContextoTenant();
            $tenantId = $contexto->tenantIdEnConexion();

            if ($tenantId === null) {
                // No se lanza excepcion aqui a proposito: se deja que la
                // politica `WITH CHECK` de PostgreSQL rechace la escritura.
                // Si la fila se creara con `tenant_id` nulo, la politica la
                // rechazaria igual, pero el mensaje seria menos claro.
                return;
            }

            $modelo->tenant_id = $tenantId;
        });
    }
}
