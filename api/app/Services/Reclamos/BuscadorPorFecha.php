<?php

declare(strict_types=1);

namespace App\Services\Reclamos;

use App\Models\Movimiento;
use App\Models\Reclamo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Busqueda de movimientos por fecha, para reclamos.
 *
 * POR QUE NO BASTA CON EL ULTIMO INGRESO
 * --------------------------------------
 * El caso que motiva este servicio: el cliente viene ahyacer un reclamo y
 * dice "mi auto estuvo estacionado el jueves". Si la pantalla solo muestra
 * el ultimo ingreso del vehiculo, el operador mira el ingreso de hoy, no ve
 * nada, y concluye que el auto nunca estuvo. La conclusion es falsa y el
 * cliente se va con la impresion de que le estamos mintiendo.
 *
 * Con la foto de ingreso y de salida, y las dos marcas de tiempo, el
 * operador abre el dia, encuentra el auto y el reclamo se resuelve con
 * evidencia en lugar de con discusiones.
 *
 * Por eso esta busqueda SIEMPRE es por ventana de fechas, y devuelve las
 * fotos de entrada y de salida de ese dia. Si la ventana no trae nada,
 * devuelve una lista vacia con un motivo: "no hay movimientos de esa
 * patente en esa fecha". Es una respuesta, no un error.
 */
class BuscadorPorFecha
{
    /**
     * Sesiones de una patente dentro de una ventana de fechas.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sesionesDePatente(
        string $tenantId,
        string $patente,
        CarbonImmutable $desde,
        CarbonImmutable $hasta
    ): Collection {
        $patenteNormalizada = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $patente));

        return Movimiento::query()
            ->where('tenant_id', $tenantId)
            ->where('patente', $patenteNormalizada)
            ->whereBetween('ocurrido_en', [$desde, $hasta])
            ->orderBy('ocurrido_en')
            ->get()
            ->groupBy('estacionamiento_id')
            ->map(function (Collection $eventos, string $estacionamientoId): array {
                $ingreso = $eventos->firstWhere('tipo', 'ingreso');
                $salida = $eventos->firstWhere('tipo', 'salida');

                return [
                    'estacionamiento_id' => $estacionamientoId,
                    'patente' => $ingreso?->patente,
                    'vin' => $ingreso?->vin,
                    'lugar_codigo' => $ingreso?->lugar_codigo,
                    'ingreso_en' => $ingreso?->ocurrido_en,
                    'salida_en' => $salida?->ocurrido_en,
                    // Las dos fotos. Sin la de salida, el operador no puede
                    // demostrar que el auto se retiro y no quedo abandonado.
                    'foto_ingreso' => $ingreso?->foto_ruta,
                    'foto_salida' => $salida?->foto_ruta,
                    'sigue_dentro' => $salida === null,
                    'operador_ingreso' => $ingreso?->operador_id,
                    'operador_salida' => $salida?->operador_id,
                ];
            })
            ->values();
    }

    /**
     * Contexto completo para mostrar junto a un reclamo.
     *
     * @return array{
     *     sesiones: Collection<int, array<string, mixed>>,
     *     encontrados: int,
     *     mensaje: string
     * }
     */
    public function contextoParaReclamo(
        string $tenantId,
        ?string $patente,
        ?string $vin,
        CarbonImmutable $desde,
        CarbonImmutable $hasta
    ): array {
        // Si hay VIN se busca por VIN: la patente puede estar en dos autos
        // de la misma familia y el VIN es el que no miente.
        if ($vin !== null && $vin !== '') {
            $sesiones = $this->sesionesDeVin($tenantId, strtoupper($vin), $desde, $hasta);
        } elseif ($patente !== null && $patente !== '') {
            $sesiones = $this->sesionesDePatente($tenantId, $patente, $desde, $hasta);
        } else {
            return [
                'sesiones' => collect(),
                'encontrados' => 0,
                'mensaje' => 'El reclamo no tiene patente ni VIN: no hay nada que buscar.',
            ];
        }

        $encontrados = $sesiones->count();

        $mensaje = $encontrados === 0
            ? 'No hay movimientos de ese vehiculo entre el '
                . $desde->format('d-m-Y') . ' y el ' . $hasta->format('d-m-Y') . '.'
            : 'Se encontraron ' . $encontrados . ' sesion(es) entre el '
                . $desde->format('d-m-Y') . ' y el ' . $hasta->format('d-m-Y') . '.';

        return [
            'sesiones' => $sesiones,
            'encontrados' => $encontrados,
            'mensaje' => $mensaje,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function sesionesDeVin(
        string $tenantId,
        string $vin,
        CarbonImmutable $desde,
        CarbonImmutable $hasta
    ): Collection {
        return Movimiento::query()
            ->where('tenant_id', $tenantId)
            ->where('vin', $vin)
            ->whereBetween('ocurrido_en', [$desde, $hasta])
            ->orderBy('ocurrido_en')
            ->get()
            ->groupBy('estacionamiento_id')
            ->map(function (Collection $eventos): array {
                $ingreso = $eventos->firstWhere('tipo', 'ingreso');
                $salida = $eventos->firstWhere('tipo', 'salida');

                return [
                    'estacionamiento_id' => $eventos->first()?->estacionamiento_id,
                    'patente' => $ingreso?->patente,
                    'vin' => $vin,
                    'lugar_codigo' => $ingreso?->lugar_codigo,
                    'ingreso_en' => $ingreso?->ocurrido_en,
                    'salida_en' => $salida?->ocurrido_en,
                    'foto_ingreso' => $ingreso?->foto_ruta,
                    'foto_salida' => $salida?->foto_ruta,
                    'sigue_dentro' => $salida === null,
                ];
            })
            ->values();
    }

    /**
     * Reclamos de un operador en una ventana de fechas.
     *
     * @return Collection<int, Reclamo>
     */
    public function reclamosEnPeriodo(
        string $tenantId,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?string $estado = null
    ): Collection {
        $consulta = Reclamo::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('fecha_incidente', [$desde->toDateString(), $hasta->toDateString()]);

        if ($estado !== null && $estado !== '') {
            $consulta->where('estado', $estado);
        }

        return $consulta->orderByDesc('fecha_incidente')->get();
    }
}
