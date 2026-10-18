<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $nombre = $this->faker->company() . ' ' . Str::upper(Str::random(3));

        return [
            'slug' => Str::slug($nombre, '.') . '.' . Str::lower($this->faker->word()) . '.cl',
            'nombre' => $nombre,
            'razon_social' => $nombre . ' Ltda.',
            'rut' => null,
            'correo_contacto' => $this->faker->companyEmail(),
            'telefono_contacto' => '+56 9 ' . $this->faker->numerify('#### ####'),
            'activo' => true,
        ];
    }

    /** Empresa inactiva, para probar que no entra al acceso. */
    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}