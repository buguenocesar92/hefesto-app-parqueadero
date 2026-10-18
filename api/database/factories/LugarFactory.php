<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lugar;
use App\Models\Parqueadero;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lugar>
 */
class LugarFactory extends Factory
{
    protected $model = Lugar::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'parqueadero_id' => Parqueadero::factory(),
            'codigo' => strtoupper($this->faker->unique()->bothify('??-##')),
            'tipo' => Lugar::TIPO_VISITANTE,
            'reservado_para' => null,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant, Parqueadero $sede): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->id,
            'parqueadero_id' => $sede->id,
        ]);
    }

    public function deTipo(string $tipo): static
    {
        return $this->state(fn (): array => ['tipo' => $tipo]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}