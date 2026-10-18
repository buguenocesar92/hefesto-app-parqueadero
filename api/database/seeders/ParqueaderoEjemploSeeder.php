<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RolUsuario;
use App\Enums\TipoVehiculo;
use App\Models\Configuracion;
use App\Models\Lugar;
use App\Models\Mensualidad;
use App\Models\Parqueadero;
use App\Models\Reclamo;
use App\Models\Tarifa;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Services\Estacionamiento\RegistradorIngreso;
use App\Services\Estacionamiento\RegistradorSalida;
use App\Services\Estacionamiento\RelojDelServidor;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Un estacionamiento de ejemplo, completo y coherente.
 *
 * Esto es lo que hay que mirar para entender el sistema en una sentada:
 *
 *  - UN operador, con cuatro personas y cuatro roles distintos.
 *  - UNA sede de veinte lugares, con lugares de visitante, mensualista, moto,
 *    carga y accesible. Los lugares en cero existencia son los que hacen
 *    visible la diferencia entre capacidad declarada y capacidad real.
 *  - CUATRO TARIFAS: una fraccion de 20 minutos, una mas corta para motos,
 *    un permiso de carga a precio fijo y una tarifa que YA VIGIO, para que
 *    se vea que el precio historico no se toca.
 *  - DOS VEHICULOS ADENTRO, con su movimiento de ingreso y su lugar real.
 *  - UNA MENSUALIDAD VIGENTE y una VENCIDA, porque los dos casos se ven
 *    distinto en pantalla y es justamente lo que hay que entender.
 *  - UN RECLAMO con su contexto, para que se vea el recorrido de buscar en el
 *    registro antes de responder.
 *
 * TODO LO QUE SE ESCRIBE CON MOVIMIENTOS, NO ESCRIBIENDO EL RESULTADO
 * -------------------------------------------------------------------
 * Los vehiculos entran con `RegistradorIngreso`, no con un `insert` en
 * `estacionamientos`. Si este seeder pudiera escribir la sesion sin su
 * movimiento, la garantia del sistema seria mentira, y la forma de
 * comprobarlo es que los propios datos de ejemplo no puedan violarla.
 *
 * DATOS FICTICIOS
 * ---------------
 * Los RUT son de ejemplo y estan marcados como tales. Los que aparecen son
 * de digitos secuenciales, que es la forma mas obvia de reconocer un RUT que
 * no existe. El digito verificador si esta calculado, para que una validacion
 * de formato no los rechace y tape el problema de verdad.
 */
class ParqueaderoEjemploSeeder extends Seeder
{
    /** RUT de ejemplo del operador. Digitos secuenciales: ficticio. */
    private const RUT_OPERADOR = '76.543.210-2';

    /** RUT de ejemplo de un mensualista. Digitos secuenciales: ficticio. */
    private const RUT_MENSUALISTA = '12.345.678-9';

    public function run(ContextoTenant $contexto): void
    {
        // `tenants` no tiene RLS: se puede leer sin contexto.
        $existente = Tenant::query()->where('slug', 'parqueadero-demo.hefestostudio.cl')->first();

        if ($existente !== null) {
            $this->command?->warn('El estacionamiento de ejemplo ya existe. No se vuelve a sembrar.');

            return;
        }

        $tenant = Tenant::query()->create([
            'slug' => 'parqueadero-demo.hefestostudio.cl',
            'nombre' => 'Estacionamiento de prueba Vicuña Mackenna',
            'razon_social' => 'Hefesto Studio Demo Ltda.',
            'rut' => self::RUT_OPERADOR,
            'correo_contacto' => 'contacto@parqueadero-demo.hefestostudio.cl',
            'telefono_contacto' => '+56 9 8765 4321',
            'activo' => true,
        ]);

        // Todo lo que sigue es dato de negocio y va con contexto: sin el, la
        // RLS no deja escribir ni una fila.
        $contexto->ejecutarAislado($tenant->id, function () use ($contexto, $tenant): void {
            Configuracion::delOperador($tenant->id);

            $personas = $this->crearPersonas($tenant);
            $sede = $this->crearSede($tenant);
            $lugares = $this->crearLugares($tenant, $sede);
            $this->crearTarifas($sede);

            // Las mensualidades van ANTES que los ingresos: si el mensualista
            // no existe todavia, el ingreso se registra sin ningun aviso y el
            // ejemplo no muestra el caso que mas importa.
            $this->crearMensualidades($tenant, $sede);

            $this->cargarVehiculosAdentro($sede, $lugares, $personas['operario']);

            $this->crearReclamo($tenant, $sede, $lugares);

            $this->command?->info(sprintf(
                'Estacionamiento de ejemplo listo. Operador %s. Para entrar, la cuenta debe existir en Authentik con el correo admin@%s',
                $tenant->slug,
                $tenant->slug
            ));
        });
    }

    /**
     * @return array{administrador: Usuario, supervisor: Usuario, operario: Usuario, lectura: Usuario}
     */
    private function crearPersonas(Tenant $tenant): array
    {
        return [
            'administrador' => Usuario::factory()->administrador()->de($tenant)->create([
                'correo' => 'admin@' . $tenant->slug,
                'nombre' => 'Patricia Molina',
            ]),
            'supervisor' => Usuario::factory()->supervisor()->de($tenant)->create([
                'correo' => 'supervisor@' . $tenant->slug,
                'nombre' => 'Rodrigo Alarcon',
            ]),
            'operario' => Usuario::factory()->operario()->de($tenant)->create([
                'correo' => 'operario@' . $tenant->slug,
                'nombre' => 'Camila Sandoval',
            ]),
            'lectura' => Usuario::factory()->lectura()->de($tenant)->create([
                'correo' => 'lectura@' . $tenant->slug,
                'nombre' => 'Jorge Bustos',
            ]),
        ];
    }

    private function crearSede(Tenant $tenant): Parqueadero
    {
        return Parqueadero::query()->create([
            'tenant_id' => $tenant->id,
            'nombre' => 'Estacionamiento Vicuña Mackenna 1200',
            'direccion' => 'Av. Vicuña Mackenna 1200, Macul, Santiago',
            // Declarada a mano, como la declara un operador. No es la cuenta
            // de lugares: esa sale de `lugares`, y el panel muestra las dos.
            'capacidad_total' => 24,
            'zona_horaria' => 'America/Santiago',
            'activo' => true,
        ]);
    }

    /**
     * @return array<string, Lugar>
     */
    private function crearLugares(Tenant $tenant, Parqueadero $sede): array
    {
        $lugares = [];

        // Doce lugares de visitante, los que se usan todos los dias.
        for ($i = 1; $i <= 12; $i++) {
            $lugares['visitante_' . $i] = Lugar::query()->create([
                'tenant_id' => $tenant->id,
                'parqueadero_id' => $sede->id,
                'codigo' => sprintf('V-%02d', $i),
                'tipo' => Lugar::TIPO_VISITANTE,
            ]);
        }

        // Cuatro de mensualista, que el operador aparta para quienes pagan.
        for ($i = 1; $i <= 4; $i++) {
            $lugares['mensualista_' . $i] = Lugar::query()->create([
                'tenant_id' => $tenant->id,
                'parqueadero_id' => $sede->id,
                'codigo' => sprintf('M-%02d', $i),
                'tipo' => Lugar::TIPO_MENSUALISTA,
                'reservado_para' => 'Mensualistas',
            ]);
        }

        // Cuatro de moto. El lugar es mas chico, pero la tarifa es otra.
        for ($i = 1; $i <= 4; $i++) {
            $lugares['moto_' . $i] = Lugar::query()->create([
                'tenant_id' => $tenant->id,
                'parqueadero_id' => $sede->id,
                'codigo' => sprintf('T-%02d', $i),
                'tipo' => Lugar::TIPO_MOTO,
            ]);
        }

        // Uno de carga, uno accesible y uno dado de baja: veinte lugares
        // activos en total.
        $lugares['carga'] = Lugar::query()->create([
            'tenant_id' => $tenant->id,
            'parqueadero_id' => $sede->id,
            'codigo' => 'C-01',
            'tipo' => Lugar::TIPO_CARGA,
            'reservado_para' => 'Carga y descarga, solo con autorizacion',
        ]);

        $lugares['accesible'] = Lugar::query()->create([
            'tenant_id' => $tenant->id,
            'parqueadero_id' => $sede->id,
            'codigo' => 'A-01',
            'tipo' => Lugar::TIPO_ACCESIBLE,
            'reservado_para' => 'Personas con movilidad reducida',
        ]);

        $lugares['fuera_servicio'] = Lugar::query()->create([
            'tenant_id' => $tenant->id,
            'parqueadero_id' => $sede->id,
            'codigo' => 'V-99',
            'tipo' => Lugar::TIPO_VISITANTE,
            'activo' => false,
        ]);

        return $lugares;
    }

    private function crearTarifas(Parqueadero $sede): void
    {
        $ahora = Carbon::now();

        // La tarifa del dia a dia: fracciones de 20 minutos a $500, con diez
        // minutos de cortesia.
        Tarifa::query()->create([
            'tenant_id' => $sede->tenant_id,
            'parqueadero_id' => $sede->id,
            'nombre' => 'Automovil, fraccion de 20 minutos',
            'tipo' => Tarifa::TIPO_POR_TIEMPO,
            'valor' => 500,
            'minutos_por_fraccion' => 20,
            'minutos_incluidos' => 10,
            'vigente_desde' => $ahora->copy()->subMonths(3),
            'vigente_hasta' => null,
            'activo' => true,
        ]);

        // La de moto, con fraccion mas corta. El mismo `por_tiempo` con otro
        // `minutos_por_fraccion`: la tarifa del tipo de vehiculo.
        Tarifa::query()->create([
            'tenant_id' => $sede->tenant_id,
            'parqueadero_id' => $sede->id,
            'nombre' => 'Moto, fraccion de 10 minutos',
            'tipo' => Tarifa::TIPO_POR_TIEMPO,
            'valor' => 300,
            'minutos_por_fraccion' => 10,
            'minutos_incluidos' => 5,
            'tipo_vehiculo' => TipoVehiculo::Moto->value,
            'vigente_desde' => $ahora->copy()->subMonths(3),
            'vigente_hasta' => null,
            'activo' => true,
        ]);

        // El permiso de carga: precio fijo, el tiempo no cambia el monto.
        Tarifa::query()->create([
            'tenant_id' => $sede->tenant_id,
            'parqueadero_id' => $sede->id,
            'nombre' => 'Carga y descarga, precio fijo',
            'tipo' => Tarifa::TIPO_POR_EVENTO,
            'valor' => 2000,
            'minutos_por_fraccion' => null,
            'minutos_incluidos' => 0,
            'vigente_desde' => $ahora->copy()->subMonths(3),
            'vigente_hasta' => null,
            'activo' => true,
        ]);

        // Una tarifa que YA TERMINO. Existe para que se vea el historico: un
        // auto que entro antes del cambio se cobra con esta, no con la
        // vigente. Es el caso que `RegistradorSalida` resuelve por la fecha
        // del ingreso.
        Tarifa::query()->create([
            'tenant_id' => $sede->tenant_id,
            'parqueadero_id' => $sede->id,
            'nombre' => 'Automovil, fraccion de 20 minutos (anterior)',
            'tipo' => Tarifa::TIPO_POR_TIEMPO,
            'valor' => 400,
            'minutos_por_fraccion' => 20,
            'minutos_incluidos' => 10,
            'vigente_desde' => $ahora->copy()->subYear(),
            'vigente_hasta' => $ahora->copy()->subMonths(3),
            'activo' => false,
        ]);
    }

    /**
     * Deja dos vehiculos adentro y uno que ya salio y pago.
     *
     * EL RELOJ SE MANIPULA A PROPOSITO
     * ---------------------------------
     * Los servicios usan `RelojDelServidor::ahora()` y no la hora del
     * proceso, y eso permite sembrar un historial verosimil sin esperar tres
     * horas.
     *
     * El orden importa: se congela la hora ANTES de cada registro. Si se
     * congelara una sola vez al principio y se cerrara la sesion del tercero
     * con una hora anterior a su ingreso, `CalculadoraTiempo::segundos()`
     * lanzaria su excepcion de "la salida no puede ser anterior al ingreso",
     * que es exactamente la proteccion que se quiere ver funcionando.
     */
    private function cargarVehiculosAdentro(Parqueadero $sede, array $lugares, Usuario $operario): void
    {
        $ingreso = app(RegistradorIngreso::class);

        // Un visitante que entro hace cuarenta minutos y sigue adentro.
        RelojDelServidor::congelar(Carbon::now()->subMinutes(40)->toImmutable());

        $ingreso->registrar($operario, [
            'patente' => 'KLPD-42',
            'vin' => 'JTDMB4C33P0012345',
            'tipo_vehiculo' => TipoVehiculo::Automobile->value,
            'lugar_id' => $lugares['visitante_1']->id,
            'color' => 'Gris',
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
        ]);

        // Un mensualista con credencial vigente, que entro hace cinco
        // minutos. Su aviso llega limpio desde `VerificadorDeAcceso`.
        RelojDelServidor::congelar(Carbon::now()->subMinutes(5)->toImmutable());

        $ingreso->registrar($operario, [
            'patente' => 'PWRT-77',
            'vin' => 'JHMCM56557C404123',
            'tipo_vehiculo' => TipoVehiculo::Automobile->value,
            'lugar_id' => $lugares['mensualista_1']->id,
            'color' => 'Rojo',
            'marca' => 'Honda',
            'modelo' => 'City',
        ]);

        // El tercero entra hace cuatro horas y sale hace tres: queda con
        // boleta emitida, para que el historial y la cuenta del dia no
        // lleguen vacios.
        RelojDelServidor::congelar(Carbon::now()->subHours(4)->toImmutable());

        $sesion = $ingreso->registrar($operario, [
            'patente' => 'GTNR-19',
            'vin' => 'KMHLB41JAB1234567',
            'tipo_vehiculo' => TipoVehiculo::Automobile->value,
            'lugar_id' => $lugares['visitante_2']->id,
            'color' => 'Negro',
            'marca' => 'Kia',
            'modelo' => 'Morning',
        ]);

        RelojDelServidor::congelar(Carbon::now()->subHours(3)->toImmutable());

        app(RegistradorSalida::class)->registrar($sesion, $operario, [
            'forma_pago' => 'efectivo',
        ]);

        RelojDelServidor::descongelar();
    }

    /**
     * Dos mensualidades: una al dia y una vencida.
     *
     * El orden en que se crean es el que explica el ejemplo. La del vehiculo
     * `PWRT-77` esta vigente y es el que entra despues; la de `ZSBB-30` esta
     * vencida y no entra, porque ahi lo interesante es el aviso, no el
     * estacionamiento.
     */
    private function crearMensualidades(Tenant $tenant, Parqueadero $sede): void
    {
        $hoy = Carbon::now();

        // Mensualista vigente: la credencial vence en veinte dias. El vehiculo
        // lo crea `RegistradorIngreso` cuando entra despues, asi que aqui
        // solo se deja el contrato apuntando al futuro vehiculo.
        $vigente = Vehiculo::query()->create([
            'tenant_id' => $tenant->id,
            'patente' => 'PWRT-77',
            'vin' => 'JHMCM56557C404123',
            'tipo_vehiculo' => TipoVehiculo::Automobile,
            'color' => 'Rojo',
            'marca' => 'Honda',
            'modelo' => 'City',
        ]);

        Mensualidad::query()->create([
            'tenant_id' => $tenant->id,
            'parqueadero_id' => $sede->id,
            'vehiculo_id' => $vigente->id,
            'cliente_nombre' => 'Jorge Fuentes Tapia',
            'cliente_rut' => self::RUT_MENSUALISTA,
            'cliente_correo' => 'ejemplo@correo.cl',
            'cliente_telefono' => '+56 9 1111 2222',
            'fecha_inicio' => $hoy->copy()->subMonth()->toDateString(),
            'fecha_termino' => null,
            'fecha_ultimo_pago' => $hoy->copy()->subMonth()->toDateString(),
            'credencial_vence_en' => $hoy->copy()->addDays(20)->toDateString(),
            'credencial_codigo' => 'CRED-0001',
            'monto_mensual' => 120000,
            'estado' => Mensualidad::ESTADO_ACTIVA,
        ]);

        // Mensualista VENCIDO: el contrato sigue vivo, la credencial no.
        // Es el caso que mas confunde: el cliente debe plata y al mismo
        // tiempo no tiene permiso para pasar. Ver docs/06.
        $vencido = Vehiculo::query()->create([
            'tenant_id' => $tenant->id,
            'patente' => 'ZSBB-30',
            'vin' => 'MR0FF3G80KT123456',
            'tipo_vehiculo' => TipoVehiculo::Automobile,
            'color' => 'Azul',
            'marca' => 'Subaru',
            'modelo' => 'Impreza',
        ]);

        Mensualidad::query()->create([
            'tenant_id' => $tenant->id,
            'parqueadero_id' => $sede->id,
            'vehiculo_id' => $vencido->id,
            'cliente_nombre' => 'Empresa Ficticia SpA',
            'cliente_rut' => self::RUT_MENSUALISTA,
            'cliente_correo' => null,
            'cliente_telefono' => '+56 2 2345 6789',
            'fecha_inicio' => $hoy->copy()->subMonths(3)->toDateString(),
            'fecha_termino' => null,
            // No pago desde hace dos meses.
            'fecha_ultimo_pago' => $hoy->copy()->subMonths(2)->toDateString(),
            'credencial_vence_en' => $hoy->copy()->subDays(40)->toDateString(),
            'credencial_codigo' => 'CRED-0002',
            'monto_mensual' => 95000,
            'estado' => Mensualidad::ESTADO_ACTIVA,
        ]);
    }

    private function crearReclamo(Tenant $tenant, Parqueadero $sede, array $lugares): void
    {
        Reclamo::query()->create([
            'tenant_id' => $tenant->id,
            'parqueadero_id' => $sede->id,
            'estacionamiento_id' => null,
            // El registro lo hace una persona de ejemplo. Si se deja en
            // `null`, la columna `registrado_por` es obligatoria y la fila no
            // se puede guardar.
            'registrado_por' => Usuario::query()
                ->where('tenant_id', $tenant->id)
                ->value('id'),
            'folio' => 'R-000001',
            'estado' => \App\Enums\EstadoReclamo::EnRevision->value,
            'fecha_incidente' => Carbon::now()->subDay()->toDateString(),
            'hora_incidente' => null,
            'patente_vista' => 'KLPD42',
            'cliente_nombre' => 'Cliente de ejemplo',
            'cliente_rut' => null,
            'cliente_contacto' => '+56 9 9999 8888',
            'descripcion' => 'El cliente dice que estaciono el auto a las 18:00 y que le cobaron de mas. '
                . 'Recuerda la patente sin guion, que es como la escribe siempre.',
            // La columna es NOT NULL. Sin foto, se deja la cadena vacia y se
            // dice explicitamente en la descripcion que no hay evidencia.
            'foto_ruta' => '',
            'resolucion' => null,
            'resuelto_por' => null,
            'resuelto_en' => null,
        ]);

        unset($lugares);
    }
}