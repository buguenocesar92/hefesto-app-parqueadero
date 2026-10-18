<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estacionamiento;
use App\Models\Movimiento;
use App\Models\Tenant;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * `parqueadero:verificar-registro`
 *
 * Revisa que el registro y las sesiones esten de acuerdo.
 *
 * POR QUE ESTA COMPROBACION EXISTE
 * --------------------------------
 * El indice unico parcial garantiza que un lugar tenga a lo sumo una sesion
 * abierta. Lo que NO garantiza ninguna base es que cada sesion tenga su
 * movimiento de ingreso.
 *
 * Y esa incoherencia es real: `RegistradorIngreso` inserta la sesion y el
 * movimiento en la misma transaccion, asi que en el camino normal no puede
 * pasar. Pero una carga de datos, una restauracion a medias o un `UPDATE`
 * hecho a mano pueden dejarla.
 *
 * Un vehiculo adentro del registro sin movimiento de ingreso es un lugar que
 * el conteo de ocupacion cuenta y que nadie puede explicar en un reclamo.
 *
 * QUE HACE Y QUE NO
 * -----------------
 * Solo LEE y REPORTA. No corrige nada. Un comando que "repara" el registro
 * automaticamente seria peor que uno que lo denuncia: escribiria movimientos
 * que nunca ocurrieron.
 */
class ParqueaderoVerificarRegistro extends Command
{
    protected $signature = 'parqueadero:verificar-registro
                            {--sede= : Revisar solo esta sede}
                            {--parche : No escribir nada, solo contar y salir}';

    protected $description = 'Revisa que cada sesion tenga su movimiento de ingreso y que las cerradas tengan salida.';

    public function handle(ContextoTenant $contexto): int
    {
        $sede = $this->option('sede');

        // `tenants` no tiene RLS: se lee sin contexto, y es la unica tabla en
        // esa situacion.
        $operadores = Tenant::query()->where('activo', true)->get();

        if ($operadores->isEmpty()) {
            $this->warn('No hay operadores activos. No hay nada que revisar.');

            return self::SUCCESS;
        }

        $totalIncoherencias = 0;

        foreach ($operadores as $operador) {
            $conteo = $contexto->ejecutarAislado(
                $operador->id,
                fn (): array => $this->revisarOperador($operador->id, is_string($sede) ? $sede : null)
            );

            $totalIncoherencias += $conteo['total'];

            $this->line(sprintf('<info>%s</info>: %d sesion(es) sin ingreso, %d cerrada(s) sin salida.',
                $operador->nombre,
                $conteo['sin_ingreso'],
                $conteo['sin_salida'],
            ));

            foreach ($conteo['detalle'] as $linea) {
                $this->line('  - ' . $linea);
            }
        }

        if ($totalIncoherencias === 0) {
            $this->info('El registro y las sesiones estan de acuerdo.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Hay %d incoherencia(s). No se modifico nada: revise una por una antes de tocar el registro.',
            $totalIncoherencias
        ));

        return self::FAILURE;
    }

    /**
     * @return array{sin_ingreso: int, sin_salida: int, detalle: list<string>, total: int}
     */
    private function revisarOperador(string $tenantId, ?string $sedeId): array
    {
        $consultaSesiones = Estacionamiento::query()
            ->where('salida_en', null)
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('movimientos')
                    ->whereColumn('movimientos.estacionamiento_id', 'estacionamientos.id')
                    ->where('movimientos.tipo', 'ingreso');
            });

        $consultaCerradas = Estacionamiento::query()
            ->whereNotNull('salida_en')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('movimientos')
                    ->whereColumn('movimientos.estacionamiento_id', 'estacionamientos.id')
                    ->where('movimientos.tipo', 'salida');
            });

        if ($sedeId !== null) {
            $consultaSesiones->where('parqueadero_id', $sedeId);
            $consultaCerradas->where('parqueadero_id', $sedeId);
        }

        $sinIngreso = $consultaSesiones->count();
        $sinSalida = $consultaCerradas->count();

        $detalle = [];

        // Solo se listan los identificadores: la lista completa puede ser
        // enorme y esto no es un inventario.
        $consultaSesiones->limit(20)->get()
            ->each(fn (Estacionamiento $sesion) => $detalle[] = sprintf(
                'Sesion abierta %s, patente %s, sin movimiento de ingreso.',
                $sesion->id,
                $sesion->patente
            ));

        $consultaCerradas->limit(20)->get()
            ->each(fn (Estacionamiento $sesion) => $detalle[] = sprintf(
                'Sesion %s, patente %s, cerrada sin movimiento de salida.',
                $sesion->id,
                $sesion->patente
            ));

        // Movimientos que apuntan a una sesion que ya no esta: no deberia
        // pasar porque la llave foranea no se borra en cascada, pero se
        // comprueba porque el diagnostico es barato.
        $movimientosHuerfanos = Movimiento::query()
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('estacionamientos')
                    ->whereColumn('estacionamientos.id', 'movimientos.estacionamiento_id');
            })
            ->limit(1)
            ->exists();

        if ($movimientosHuerfanos) {
            $detalle[] = 'Hay movimientos sin sesion asociada.';
        }

        return [
            'sin_ingreso' => $sinIngreso,
            'sin_salida' => $sinSalida,
            'detalle' => $detalle,
            'total' => $sinIngreso + $sinSalida,
        ];
    }
}