<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\TipoVehiculo;
use App\Models\Estacionamiento;
use App\Models\Lugar;
use App\Models\Movimiento;
use App\Models\Parqueadero;
use App\Models\Tarifa;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas del parqueadero.
 *
 * PostgreSQL DE VERDAD, NO SQLITE
 * -------------------------------
 * El aislamiento entre operadores esta implementado con Row Level Security,
 * y la inmutabilidad del registro con un gatillo. En SQLite ninguna de las
 * dos cosas existe, y las pruebas de aislamiento pasarian en verde sin
 * comprobar absolutamente nada. Por eso `phpunit.xml` fija `DB_CONNECTION`
 * en `pgsql` a proposito, y no como un valor por omision olvidado.
 *
 * `actingAs()` NO ALCANZA
 * ----------------------
 * El middleware `ResolverTenant` corre dentro del grupo `web`, que estas
 * pruebas no ejecutan cuando llaman a un servicio directo. `actingAs()` deja
 * a la persona autenticada en Laravel, pero NO pone `app.tenant_id` en la
 * conexion, y sin esa variable la RLS no tendria contra que filtrar: todas
 * las consultas fallarian con la excepcion de `app_tenant_id()`.
 *
 * Por eso existe `comoUsuario()`, que hace las dos cosas. Las pruebas que
 * llaman a la API por HTTP si atraviesan el middleware, y por eso hay dos
 * caminos distintos a proposito.
 */
abstract class TestCase extends BaseTestCase
{
    /** Operador fijado para la prueba en curso. */
    protected ?string $operadorDePrueba = null;

    /**
     * Autentica a una persona Y fija su contexto de operador en la base.
     */
    protected function comoUsuario(Usuario $usuario): static
    {
        $this->actingAs($usuario);

        return $this->fijarOperador($usuario->tenant_id);
    }

    /**
     * Emula lo que hace `App\Http\Middleware\ResolverTenant`.
     *
     * Aqui se usa `set_config(..., false)`, con alcance de SESION. En la
     * aplicacion real es al reves: alcance de TRANSACCION, para que el worker
     * de colas, que reutiliza la misma conexion, no arrastre el operador de
     * otro trabajo.
     *
     * En las pruebas cada caso abre su propia aplicacion y su propia
     * conexion, asi que el valor no sobrevive entre pruebas y no hace falta
     * el alcance transaccional. El alcance de transaccion se comprueba
     * aparte, en `test_el_contexto_no_sobrevive_a_la_transaccion`.
     */
    protected function fijarOperador(?string $operadorId): static
    {
        if ($operadorId === null) {
            return $this;
        }

        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, $operadorId]);

        $this->operadorDePrueba = $operadorId;

        return $this;
    }

    /**
     * Crea un operador con una sede, lugares, una tarifa y una persona.
     *
     * @return array{
     *     operador: Tenant,
     *     sede: Parqueadero,
     *     administrador: Usuario,
     *     supervisor: Usuario,
     *     operario: Usuario,
     *     lectura: Usuario,
     *     tarifa: Tarifa,
     *     lugares: array<int, Lugar>
     * }
     */
    protected function crearOperador(string $dominio = 'ejemplo.cl', int $cantidadLugares = 4): array
    {
        // `tenants` no tiene RLS: se puede crear sin contexto.
        $operador = Tenant::factory()->create([
            'slug' => $dominio,
            'nombre' => 'Estacionamiento ' . $dominio,
        ]);

        $this->fijarOperador($operador->id);

        $administrador = Usuario::factory()->administrador()->de($operador)->create([
            'correo' => 'admin@' . $dominio,
        ]);

        $supervisor = Usuario::factory()->supervisor()->de($operador)->create([
            'correo' => 'supervisor@' . $dominio,
        ]);

        $operario = Usuario::factory()->operario()->de($operador)->create([
            'correo' => 'operario@' . $dominio,
        ]);

        $lectura = Usuario::factory()->lectura()->de($operador)->create([
            'correo' => 'lectura@' . $dominio,
        ]);

        $sede = Parqueadero::query()->create([
            'tenant_id' => $operador->id,
            'nombre' => 'Sede central',
            'direccion' => 'Direccion de prueba',
            'capacidad_total' => $cantidadLugares,
            'zona_horaria' => 'America/Santiago',
            'activo' => true,
        ]);

        $lugares = [];

        for ($i = 1; $i <= $cantidadLugares; $i++) {
            $lugares[$i] = Lugar::query()->create([
                'tenant_id' => $operador->id,
                'parqueadero_id' => $sede->id,
                'codigo' => sprintf('V-%02d', $i),
                'tipo' => Lugar::TIPO_VISITANTE,
            ]);
        }

        // Fraccion de 20 minutos a $500, con 10 minutos de cortesia. Es la
        // tarifa del dia a dia en un estacionamiento.
        $tarifa = Tarifa::query()->create([
            'tenant_id' => $operador->id,
            'parqueadero_id' => $sede->id,
            'nombre' => 'Fraccion de 20 minutos',
            'tipo' => Tarifa::TIPO_POR_TIEMPO,
            'valor' => 500,
            'minutos_por_fraccion' => 20,
            'minutos_incluidos' => 10,
            'tipo_vehiculo' => null,
            'vigente_desde' => now()->subDays(30),
            'vigente_hasta' => null,
            'activo' => true,
        ]);

        return [
            'operador' => $operador,
            'sede' => $sede,
            'administrador' => $administrador,
            'supervisor' => $supervisor,
            'operario' => $operario,
            'lectura' => $lectura,
            'tarifa' => $tarifa,
            'lugares' => $lugares,
        ];
    }

    /**
     * Deja un vehiculo adentro, con su movimiento de ingreso.
     *
     * Va por `RegistradorIngreso` y no por un `insert` directo: el sistema no
     * permite escribir una sesion sin su movimiento, y una prueba que lo
     * esquivara estaria comprobando algo que la aplicacion real no permite.
     */
    protected function dejarVehiculoAdentro(
        Usuario $operario,
        Lugar $lugar,
        string $patente = 'ABCD12',
        string $vin = 'JTDMB4C33P0000001',
        TipoVehiculo $tipo = TipoVehiculo::Automobile,
    ): Estacionamiento {
        return app(\App\Services\Estacionamiento\RegistradorIngreso::class)->registrar($operario, [
            'patente' => $patente,
            'vin' => $vin,
            'tipo_vehiculo' => $tipo->value,
            'lugar_id' => $lugar->id,
        ]);
    }

    /**
     * Lanza la excepcion de base que se espera, sin repetir el try/catch.
     */
    protected function esperarFalloDeBase(callable $operacion): void
    {
        try {
            $operacion();
        } catch (QueryException) {
            return;
        }

        $this->fail('Se esperaba un fallo de PostgreSQL y la operacion paso sin problema.');
    }
}