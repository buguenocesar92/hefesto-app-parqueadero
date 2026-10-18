<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeParqueadero;
use App\Http\Requests\StoreMensualidadRequest;
use App\Models\Mensualidad;
use App\Models\Parqueadero;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Services\Auditoria\RegistradorDeBitacora;
use App\Services\Estacionamiento\RelojDelServidor;
use App\Services\Mensualidades\VerificadorDeAcceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Mensualidades: el derecho a pasar sin pagar por operacion.
 *
 * TRES ESTADOS Y UNA REGLA QUE NO SE ADELANTA
 * ---------------------------------------------
 *
 *  - `activa`: el contrato esta vigente.
 *  - `suspendida`: el contrato sigue existiendo pero no permite pasar.
 *  - `terminada`: se acabo el acuerdo. No hay nada que cobrar.
 *
 * Una mensualidad NO vence sola por que paso la fecha. El paso del tiempo es
 * un aviso, no una accion: la tiene que ejecutar una persona. Un sistema que
 * termina contratos solo se equivoca de dia y se lleva a un cliente que si
 * pago.
 *
 * LO QUE ESTA PROTEGIDO
 * ---------------------
 * El listado NO trae el RUT, el correo ni el telefono del titular. Para ver
 * eso hay que abrir la ficha con `verContacto`, que exige mas rol. El listado
 * se ve dozens de veces al dia; la ficha, unas pocas.
 */
class MensualidadController extends Controller
{
    public function __construct(private readonly RegistradorDeBitacora $bitacora) {}

    public function index(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Mensualidad::class);

        $mensualidades = Mensualidad::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->with('vehiculo')
            ->orderBy('credencial_vence_en')
            ->limit(500)
            ->get()
            ->map(fn (Mensualidad $mensualidad): array => $this->presentar($mensualidad, conContacto: false));

        return response()->json(['mensualidades' => $mensualidades]);
    }

    /** Ficha completa, con el dato de contacto del titular. */
    public function show(Mensualidad $mensualidad): JsonResponse
    {
        Gate::authorize('view', $mensualidad);

        $puedeVerContacto = Gate::allows('verContacto', $mensualidad);

        return response()->json(
            $this->presentar($mensualidad->load('vehiculo'), conContacto: $puedeVerContacto)
        );
    }

    /**
     * Lo que hay que cobrar: vencidas y proximas a vencer.
     *
     * La ventana de anticipacion sale de `configuraciones.dias_aviso_vencimiento`
     * si no se manda otra. Es la pantalla del cierre de mes.
     */
    public function porCobrar(Request $request, VerificadorDeAcceso $verificador): JsonResponse
    {
        Gate::authorize('viewAny', Mensualidad::class);

        $dias = (int) $request->query(
            'dias',
            (int) config('parqueadero.dias_aviso_vencimiento', 15)
        );

        $cobrables = $verificador->porCobrar($request->user()->tenant_id, $dias);

        return response()->json([
            'dias_anticipacion' => $dias,
            'mensualidades' => $cobrables->all(),
        ]);
    }

    public function store(StoreMensualidadRequest $request, Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('create', Mensualidad::class);

        $datos = $request->validated();

        $vehiculo = Vehiculo::query()->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($datos['vehiculo_id']);

        if ($vehiculo->mensualidad()->exists()) {
            throw new ErrorDeParqueadero(
                'Ese vehiculo ya tiene una mensualidad activa. Termine la anterior antes de crear otra.',
                'vehiculo_con_mensualidad',
            );
        }

        $mensualidad = Mensualidad::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'parqueadero_id' => $parqueadero->id,
            'vehiculo_id' => $vehiculo->id,
            'cliente_nombre' => $datos['cliente_nombre'],
            'cliente_rut' => $datos['cliente_rut'] ?? null,
            'cliente_correo' => $datos['cliente_correo'] ?? null,
            'cliente_telefono' => $datos['cliente_telefono'] ?? null,
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_termino' => $datos['fecha_termino'] ?? null,
            'fecha_ultimo_pago' => $datos['fecha_ultimo_pago'] ?? null,
            'credencial_vence_en' => $datos['credencial_vence_en'],
            'credencial_codigo' => $datos['credencial_codigo'] ?? null,
            'monto_mensual' => (int) $datos['monto_mensual'],
            'estado' => $datos['estado'] ?? Mensualidad::ESTADO_ACTIVA,
        ]);

        $this->bitacora->registrar(
            tenantId: $mensualidad->tenant_id,
            usuario: $request->user(),
            ambito: 'mensualidades',
            accion: 'crear',
            recursoTipo: Mensualidad::class,
            recursoId: $mensualidad->id,
            descripcion: sprintf('Mensualidad creada para el vehiculo %s.', $vehiculo->patente),
            // Sin el nombre ni el RUT del titular: la bitacora se entrega a
            // terceros. Ver docs/07-SEGURIDAD.md.
            datos: [
                'vehiculo_id' => $vehiculo->id,
                'parqueadero_id' => $parqueadero->id,
                'monto_mensual' => $mensualidad->monto_mensual,
            ],
            direccionIp: $request->ip(),
        );

        return response()->json(
            $this->presentar($mensualidad->load('vehiculo'), conContacto: true),
            201
        );
    }

    /**
     * Registra un pago.
     *
     * Un pago RENUEVA la credencial: por omision, un mes desde hoy. No se
     * acumula con la anterior sin decidir cuando empieza el nuevo periodo: un
     * cliente que paga tres meses por adelantado tiene que poder decidir si
     * acumula o si renueva, y esa decision no se toma sola.
     */
    public function registrarPago(Request $request, Mensualidad $mensualidad): JsonResponse
    {
        Gate::authorize('registrarPago', $mensualidad);

        $datos = $request->validate([
            'monto' => ['nullable', 'integer', 'min:0'],
            'acumular' => ['nullable', 'boolean'],
            'meses' => ['nullable', 'integer', 'min:1', 'max:24'],
        ], [], [
            'acumular' => 'acumulación',
            'meses' => 'meses pagados',
        ]);

        $meses = (int) $datos['meses'] ?? 1;
        $hoy = Carbon::now();

        if (($datos['acumular'] ?? false) === true && $mensualidad->fecha_ultimo_pago !== null) {
            // El periodo nuevo arranca cuando termina el que ya estaba pagado.
            $inicio = $mensualidad->credencial_vence_en->addMonthsNoOverflow($meses);
        } else {
            $inicio = $hoy->copy();
        }

        $mensualidad->fecha_ultimo_pago = $hoy->toDateString();
        $mensualidad->credencial_vence_en = $inicio->addMonthsNoOverflow($meses)->toDateString();

        // Pagar deja de estar suspendido: el cliente esta al dia.
        if ($mensualidad->estado === Mensualidad::ESTADO_SUSPENDIDA) {
            $mensualidad->estado = Mensualidad::ESTADO_ACTIVA;
        }

        $mensualidad->save();

        $this->bitacora->registrar(
            tenantId: $mensualidad->tenant_id,
            usuario: $request->user(),
            ambito: 'mensualidades',
            accion: 'cobrar',
            recursoTipo: Mensualidad::class,
            recursoId: $mensualidad->id,
            descripcion: sprintf('Pago registrado: %d mes(es).', $meses),
            datos: [
                'vehiculo_id' => $mensualidad->vehiculo_id,
                'monto' => $datos['monto'] ?? null,
                'meses' => $meses,
                'acumular' => $datos['acumular'] ?? false,
            ],
            direccionIp: $request->ip(),
        );

        return response()->json($this->presentar($mensualidad->fresh(), conContacto: false));
    }

    /**
     * Suspende o reactiva una mensualidad.
     *
     * Es distinto de terminarla: la suspension se puede levantar cuando el
     * cliente paga. Terminar es el cierre del contrato.
     */
    public function cambiarEstado(Request $request, Mensualidad $mensualidad): JsonResponse
    {
        Gate::authorize('update', $mensualidad);

        $datos = $request->validate([
            'estado' => ['required', 'string', 'in:' . implode(',', Mensualidad::ESTADOS)],
            'fecha_termino' => ['nullable', 'date_format:Y-m-d'],
        ], [], [
            'estado' => 'estado',
            'fecha_termino' => 'fecha de termino',
        ]);

        if ($datos['estado'] === Mensualidad::ESTADO_TERMINADA) {
            Gate::authorize('terminar', $mensualidad);
        }

        $mensualidad->estado = $datos['estado'];

        if (isset($datos['fecha_termino'])) {
            $mensualidad->fecha_termino = $datos['fecha_termino'];
        }

        $mensualidad->save();

        return response()->json($this->presentar($mensualidad->fresh(), conContacto: false));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Mensualidad $mensualidad, bool $conContacto): array
    {
        $datos = [
            'id' => $mensualidad->id,
            'parqueadero_id' => $mensualidad->parqueadero_id,
            'vehiculo_id' => $mensualidad->vehiculo_id,
            'patente' => $mensualidad->vehiculo?->patente,
            'vin' => $mensualidad->vehiculo?->vin,
            'cliente_nombre' => $mensualidad->cliente_nombre,
            'fecha_inicio' => $mensualidad->fecha_inicio->toDateString(),
            'fecha_termino' => $mensualidad->fecha_termino?->toDateString(),
            'fecha_ultimo_pago' => $mensualidad->fecha_ultimo_pago?->toDateString(),
            'credencial_vence_en' => $mensualidad->credencial_vence_en->toDateString(),
            'credencial_codigo' => $mensualidad->credencial_codigo,
            'monto_mensual' => $mensualidad->monto_mensual,
            'estado' => $mensualidad->estado,
            'contrato_vigente' => $mensualidad->contratoVigente(RelojDelServidor::ahora()),
            'dias_para_vencer' => (int) now()->diffInDays(
                $mensualidad->credencial_vence_en->startOfDay(),
                false
            ),
        ];

        // El dato de contacto no viaja en el listado: se decide aqui, no en
        // la pantalla.
        if ($conContacto) {
            $datos['cliente_rut'] = $mensualidad->cliente_rut;
            $datos['cliente_correo'] = $mensualidad->cliente_correo;
            $datos['cliente_telefono'] = $mensualidad->cliente_telefono;
        }

        return $datos;
    }
}