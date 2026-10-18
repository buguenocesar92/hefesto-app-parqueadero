<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoVehiculo;
use App\Models\Tenant;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vehiculo>
 */
class VehiculoFactory extends Factory
{
    protected $model = Vehiculo::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Formato chileno de dos letras, cuatro digitos y dos letras.
            'patente' => strtoupper(Str::random(2)) . $this->faker->numerify('####') . strtoupper(Str::random(2)),
            // 17 caracteres sin I, O ni Q, que es el alfabeto real del VIN.
            'vin' => strtoupper(Str::random(17)),
            'tipo_vehiculo' => TipoVehiculo::Automobile,
            'color' => $this->faker->randomElement(['Blanco', 'Negro', 'Gris', 'Azul', 'Rojo']),
            'marca' => $this->faker->randomElement(['Mazda', 'Toyota', 'Hyundai', 'Kia', 'Chevrolet']),
            'modelo' => $this->faker->randomElement(['H3', 'Corolla', 'Accent', 'Morning', 'Onix']),
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    public function deTipo(TipoVehiculo $tipo): static
    {
        return $this->state(fn (): array => ['tipo_vehiculo' => $tipo]);
    }
}