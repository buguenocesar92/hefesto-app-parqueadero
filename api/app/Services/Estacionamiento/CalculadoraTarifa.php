<?php

declare(strict_types=1);

namespace App\Services\Estacionamiento;

use App\Models\Tarifa;
use InvalidArgumentException;
use RuntimeException;

/**
 * Calculo del cobro, en el servidor.
 *
 * NADA DE ESTO VIENE DEL CLIENTE
 * -----------------------------
 * La peticion HTTP no transporta ni monto ni minutos. Transporta el
 * `estacionamiento_id`. El tiempo sale de las marcas de la base, la tarifa
 * se busca por vigencia y el resultado lo arma esta clase. Un navegador que
 * mande `total: 0` o `minutos: 999999` no cambia el resultado, porque esos
 * campos ni siquiera se leen de la peticion.
 *
 * COMO SE COBRA UNA TARIFA POR TIEMPO
 * ----------------------------------
 * El valor de la tarifa es el precio de una fraccion de
 * `minutos_por_fraccion` minutos. Ejemplo tipico de un operador chileno:
 * $500 por cada 20 minutos, con 10 minutos de cortesia.
 *
 * El cobro es: cuantas fracciones completas se pasaron, por el valor de cada
 * una. La fraccion se completa con segundos, no con minutos redondeados:
 * un auto que estuvo 20 minutos y 1 segundo paga dos fracciones. Es la
 * lectura mas comun y la unica que el operador puede explicar en una linea.
 *
 * POR QUE UN SERVICIO Y NO UN METODO EN EL CONTROLADOR
 * ---------------------------------------------------
 * Porque el mismo calculo se usa en tres lugares: la boleta, la pantalla de
 * salida (que muestra el estimado antes de cobrar) y la reconstruccion de
 * una cuenta a partir del registro cuando el cliente perdio el ticket. Si
 * estuviera en el controlador, esas tres podrian divergir, y la tercera
 * existe justamente para ser la version confiable.
 */
class CalculadoraTarifa
{
    public function __construct(private readonly CalculadoraTiempo $tiempo) {}

    /**
     * Resultado del calculo, con el detalle que se guarda en la boleta.
     *
     * @return array{
     *     segundos_permanencia: int,
     *     minutos_cobrados: int,
     *     subtotal: int,
     *     detalle: array<string, mixed>
     * }
     */
    public function calcular(Tarifa $tarifa, int $segundosPermanencia): array
    {
        if ($segundosPermanencia < 0) {
            throw new InvalidArgumentException(
                'No se puede calcular un cobro con tiempo negativo.'
            );
        }

        // Tarifa fija: el tiempo no afecta el monto. Se cobra lo mismo a las
        // 14:00 y a las 03:00.
        if ($tarifa->tipo === 'por_evento') {
            return [
                'segundos_permanencia' => $segundosPermanencia,
                'minutos_cobrados' => 0,
                'subtotal' => (int) $tarifa->valor,
                'detalle' => [
                    'metodo' => 'por_evento',
                    'tarifa' => $tarifa->nombre,
                    'valor' => (int) $tarifa->valor,
                    'explicacion' => 'Tarifa fija por evento: el tiempo no modifica el monto.',
                ],
            ];
        }

        if ($tarifa->tipo !== 'por_tiempo') {
            throw new RuntimeException(
                'Tipo de tarifa desconocido: ' . $tarifa->tipo . '. '
                . 'Solo se aceptan "por_tiempo" y "por_evento".'
            );
        }

        $minutosPorFraccion = $tarifa->minutos_por_fraccion;

        if ($minutosPorFraccion === null || $minutosPorFraccion < 1) {
            throw new RuntimeException(
                'La tarifa "' . $tarifa->nombre . '" es por tiempo pero no tiene minutos_por_fraccion. '
                . 'Revise la tabla tarifas.'
            );
        }

        $cortesia = (int) ($tarifa->minutos_incluidos ?? 0);

        // Minutos netos tras la cortesia, sin redondear todavia.
        $minutosBrutos = (int) ceil($segundosPermanencia / 60);
        $minutosNetos = max(0, $minutosBrutos - $cortesia);

        // Segundos que se cobran despues de la cortesia. Se calcula en
        // segundos para que la fraccion se complete con exactitud: si un
        // auto esta 20 minutos y 1 segundo, cobra dos fracciones.
        $segundosCobrables = max(0, $segundosPermanencia - ($cortesia * 60));

        $fracciones = (int) ceil($segundosCobrables / ($minutosPorFraccion * 60));

        $subtotal = $fracciones * (int) $tarifa->valor;

        return [
            'segundos_permanencia' => $segundosPermanencia,
            'minutos_cobrados' => $minutosNetos,
            'subtotal' => $subtotal,
            'detalle' => [
                'metodo' => 'por_tiempo',
                'tarifa' => $tarifa->nombre,
                'minutos_por_fraccion' => $minutosPorFraccion,
                'valor_por_fraccion' => (int) $tarifa->valor,
                'minutos_cortesia' => $cortesia,
                'minutos_brutos' => $minutosBrutos,
                'minutos_netos' => $minutosNetos,
                'fracciones' => $fracciones,
                'tiempo' => $this->tiempo->desglosar($segundosPermanencia),
                'explicacion' => sprintf(
                    '%d minutos de cortesia. Se cobro %d fraccion(es) de %d minutos a $%d cada una.',
                    $cortesia,
                    $fracciones,
                    $minutosPorFraccion,
                    (int) $tarifa->valor
                ),
            ],
        ];
    }

    /**
     * Cuenta del dia, sin que nadie tenga que hacerla a mano.
     *
     * Suma boletas emitidas en una ventana de tiempo. La suma se hace en la
     * base y no en PHP justamente para no traer miles de filas al servidor
     * de la aplicacion.
     */
    public function totalDelDia(string $parqueaderoId, string $desde, string $hasta): array
    {
        $fila = \Illuminate\Support\Facades\DB::table('boletas')
            ->where('parqueadero_id', $parqueaderoId)
            ->where('estado', 'emitida')
            ->whereBetween('emitida_en', [$desde, $hasta])
            ->selectRaw('COUNT(*) AS boletas, COALESCE(SUM(total), 0) AS total')
            ->first();

        return [
            'boletas' => (int) $fila->boletas,
            'total' => (int) $fila->total,
        ];
    }
}
