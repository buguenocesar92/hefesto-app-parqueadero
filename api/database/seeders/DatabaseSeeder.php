<?php

declare(strict_types=1);

use App\Services\Tenancy\ContextoTenant;
use Database\Factories\UsuarioFactory;
use Database\Seeders\ParqueaderoEjemploSeeder;
use Illuminate\Database\Seeder;

/**
 * Punto de entrada de las semillas.
 *
 * `db:seed` siembra el estacionamiento de ejemplo completo.
 * Ver docs/06-INGRESO-VEHICULO.md para el recorrido.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ParqueaderoEjemploSeeder::class);
    }
}