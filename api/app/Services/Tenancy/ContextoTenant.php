<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contexto de operador de la peticion.
 *
 * La RLS de PostgreSQL (migracion 2026_10_17_000014) lee el operador desde
 * la variable de sesion `app.tenant_id`. Esta clase es la UNICA que la
 * escribe. Si alguien la fija por otro lado, el comportamiento deja de estar
 * garantizado: por eso `establecer()` es el unico punto de entrada.
 *
 * ALCANCE DE TRANSACCION
 * ----------------------
 * Se usa `set_config(..., true)`: el valor desaparece al confirmar o revertir
 * la transaccion. Con un `SET` normal (ambito de sesion) el valor sobrevivia
 * a la transaccion y, en el worker de colas, que reutiliza la misma conexion
 * para trabajos de operadores distintos, una peticion podria leer datos de
 * otro operador. Ese es exactamente el fallo que este diseno evita.
 *
 * Por eso `establecer()` EXIGE estar dentro de una transaccion y lanza
 * excepcion si no lo esta.
 */
class ContextoTenant
{
    /** Variable de sesion de PostgreSQL que lee la politica RLS. */
    public const VARIABLE = 'app.tenant_id';

    /**
     * Fija el operador de la peticion en la conexion actual.
     *
     * @throws RuntimeException si no hay una transaccion abierta.
     */
    public function establecer(string $tenantId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'No se puede fijar el contexto de operador fuera de una transaccion. '
                . 'Use App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
            );
        }

        DB::select('SELECT set_config(?, ?, true)', [self::VARIABLE, $tenantId]);
    }

    /**
     * Ejecuta una operacion dentro de una transaccion con el operador fijo.
     * Al salir, PostgreSQL descarta el valor de la variable.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    public function ejecutarAislado(string $tenantId, Closure $operacion): mixed
    {
        return DB::transaction(function () use ($tenantId, $operacion) {
            $this->establecer($tenantId);

            return $operacion();
        });
    }

    /**
     * Lee la variable de sesion sin pasar por `public.app_tenant_id()`,
     * que lanza excepcion cuando no hay contexto.
     *
     * Sirve para diagnostico. Si devuelve null, ninguna politica RLS de la
     * base va a devolver filas.
     */
    public function tenantIdEnConexion(): ?string
    {
        $fila = DB::selectOne('SELECT current_setting(?, true) AS valor', [self::VARIABLE]);

        $valor = $fila->valor ?? null;

        return $valor === null || $valor === '' ? null : $valor;
    }

    /** Identificador del operador autenticado, si lo hay. */
    public function tenantIdSolicitado(): ?string
    {
        $usuario = auth()->user();

        return $usuario?->tenant_id;
    }
}
