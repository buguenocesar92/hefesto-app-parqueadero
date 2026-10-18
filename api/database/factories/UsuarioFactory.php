<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolUsuario;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // El `authentik_subject` es la identidad en el proveedor. En las
            // pruebas no hay proveedor: se pone un valor unico y ficticio,
            // que es justo lo que la columna necesita para no chocar.
            'authentik_subject' => 'prueba-' . $this->faker->unique()->uuid(),
            'nombre' => $this->faker->name(),
            'correo' => $this->faker->unique()->safeEmail(),
            'rol' => RolUsuario::Operario,
            'activo' => true,
            'ultimo_ingreso' => null,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => RolUsuario::Administrador]);
    }

    public function supervisor(): static
    {
        return $this->state(fn (): array => ['rol' => RolUsuario::Supervisor]);
    }

    public function operario(): static
    {
        return $this->state(fn (): array => ['rol' => RolUsuario::Operario]);
    }

    public function lectura(): static
    {
        return $this->state(fn (): array => ['rol' => RolUsuario::Lectura]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}