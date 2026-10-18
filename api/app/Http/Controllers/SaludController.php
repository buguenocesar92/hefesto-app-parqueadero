<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Salud del servicio.
 *
 * La usa el orquestador (`docker compose`) y responde a "¿esta caido el
 * parqueadero?" sin necesitar credenciales. Un chequeo de salud que pide
 * credenciales no lo puede correr nadie justo cuando hace falta.
 *
 * LO QUE NO DICE
 * --------------
 * No dice cuantos operadores hay, ni cuantos lugares, ni cuanto se cobro. Por
 * eso es publico. Un endpoint de salud que filtra datos de negocio se
 * convierte en una pantalla de inventario para cualquiera que lo consulte.
 *
 * POR QUE EL AISLAMIENTO ES PARTE DE LA SALUD
 * -------------------------------------------
 * Si la base responde pero la funcion `public.app_tenant_id()` no existe, el
 * servicio esta de pie y DESPINANDO datos de otros operadores. Eso es peor
 * que estar caido, asi que se reporta como degradado: la comprobacion tiene
 * que notar que la RLS se aplico a medias.
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $componentes = [
            'base' => $this->estadoBase(),
            'cache' => $this->estadoRedis(),
        ];

        $todoBien = ! in_array(false, array_column($componentes, 'ok'), true);

        return response()->json([
            'estado' => $todoBien ? 'ok' : 'degradado',
            'componentes' => $componentes,
            'momento' => now()->toIso8601String(),
        ], $todoBien ? 200 : 503);
    }

    /**
     * @return array{ok: bool, detalle: string}
     */
    private function estadoBase(): array
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable $error) {
            return ['ok' => false, 'detalle' => 'La base no responde: ' . $error->getMessage()];
        }

        try {
            $existe = DB::selectOne("SELECT to_regprocedure('public.app_tenant_id()') AS funcion");

            if ($existe === null || $existe->funcion === null) {
                return [
                    'ok' => false,
                    'detalle' => 'La funcion de contexto de operador no existe: falta aplicar la migracion de Row Level Security.',
                ];
            }
        } catch (Throwable $error) {
            return ['ok' => false, 'detalle' => 'No se pudo verificar el aislamiento: ' . $error->getMessage()];
        }

        return ['ok' => true, 'detalle' => 'Base accesible con el aislamiento aplicado.'];
    }

    /**
     * Redis sostiene la cache de ocupacion, las colas y el planificador.
     *
     * Sin el, la API sigue respondiendo: la ocupacion se recalcula contra
     * PostgreSQL cada vez. Es degradado, no caido.
     *
     * @return array{ok: bool, detalle: string}
     */
    private function estadoRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['ok' => true, 'detalle' => 'Cache y colas operativas.'];
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'detalle' => 'Redis no responde. La API sigue operando, pero la ocupacion se recalcula en cada consulta y los avisos de vencimiento no se envian solos: '
                    . $error->getMessage(),
            ];
        }
    }
}