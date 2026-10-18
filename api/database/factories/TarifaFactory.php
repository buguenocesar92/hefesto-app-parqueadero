<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tarifa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Tarifa>
 */
class TarifaFactory extends Factory
{
    protected $model = Tarifa::class;

    /**
     * Tarifa por tiempo, que es la del dia a dia en un estacionamiento:
     * una fraccion de 20 minutos a $500.
     */
    public function definition(): array
    {
        return [
            'parqueadero_id' => \App\Models\Parqueadero::factory(),
            'nombre' => 'Fraccion de 20 minutos',
            'tipo' => Tarifa::TIPO_POR_TIEMPO,
            'valor' => 500,
            'minutos_por_fraccion' => 20,
            'minutos_incluidos' => 10,
            'tipo_vehiculo' => null,
            'vigente_desde' => Carbon::now()->subDays(30),
            'vigente_hasta' => null,
            'activo' => true,
        ];
    }

    public function porEvento(int $valor = 2000): static
    {
        return $this->state(fn (): array => [
            'nombre' => 'Permiso de carga y descarga',
            'tipo' => Tarifa::TIPO_POR_EVENTO,
            'valor' => $valor,
            'minutos_por_fraccion' => null,
            'minutos_incluidos' => 0,
        ]);
    }

    /** Sin minutos por fraccion: la tarifa que el calculo rechaza. */
    public function incompleta(): static
    {
        return $this->state(fn (): array => ['minutos_por_fraccion' => null]);
    }
}