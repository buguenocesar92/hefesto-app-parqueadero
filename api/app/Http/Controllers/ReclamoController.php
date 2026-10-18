<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoReclamo;
use App\Exceptions\ErrorDeParqueadero;
use App\Http\Requests\StoreReclamoRequest;
use App\Http\Requests\UpdateReclamoRequest;
use App\Models\Estacionamiento;
use App\Models\Parqueadero;
use App\Models\Reclamo;
use App\Models\Usuario;
use App\Services\Auditoria\RegistradorDeBitacora;
use App\Services\Reclamos\BuscadorPorFecha;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Reclamos: donde se junta el dato personal de los clientes.
 *
 * EL ORDEN EN QUE SE TRABAJA UN RECLAMO
 * -------------------------------------
 *
 *  1. El cliente cuenta lo que pasó en el mostrador. Se registra el reclamo
 *     con su fecha y lo que dijo, con sus palabras.
 *  2. Se busca en el REGISTRO: que vehiculo entro, a que hora, a que lugar y
 *     si hay foto. Eso lo hace `BuscadorPorFecha`.
 *  3. Con eso encima, se responde: se resuelve o se rechaza, escribiendo por
 *     que.
 *
 * El paso 2 es el que justifica todo lo demas. Sin el registro, "el cliente
 * dice que no le cobramos" y "no cobramos" son la misma frase.
 *
 * POR QUE HAY UNA PETICION DE CONTEXTO SEPARADA
 * -------------------------------------------
 * El contexto se pide ANTES de escribir el reclamo. Sirve para mostrarle al
 * operario "el martes entro un auto con esa placa" mientras el cliente sigue
 * en la ventanilla, que es cuando todavia se le puede preguntar algo.
 */
class ReclamoController extends Controller
{
    public function __construct(private readonly RegistradorDeBitacora $bitacora) {}

    public function index(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Reclamo::class);

        $reclamos = Reclamo::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->orderByDesc('fecha_incidente')
            ->limit(300)
            ->get()
            ->map(fn (Reclamo $reclamo): array => $this->presentar($reclamo, conFicha: false));

        return response()->json(['reclamos' => $reclamos]);
    }

    /**
     * Que paso con esa patente en esa ventana de fechas.
     *
     * Es la pantalla de escritorio del reclamo, y la que mas se usa: se llama
     * con lo que dijo el cliente y devuelve lo que hay en el registro.
     */
    public function contexto(Request $request, BuscadorPorFecha $buscador): JsonResponse
    {
        Gate::authorize('viewAny', Reclamo::class);

        $datos = $request->validate([
            'patente' => ['nullable', 'string', 'max:12'],
            'vin' => ['nullable', 'string', 'size:17'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ], [], [
            'desde' => 'fecha inicial',
            'hasta' => 'fecha final',
        ]);

        if (($datos['patente'] ?? null) === null && ($datos['vin'] ?? null) === null) {
            return response()->json([
                'mensaje' => 'Indique la patente o el VIN para buscar en el registro.',
                'codigo' => 'busqueda_sin_criterio',
            ], 422);
        }

        $contexto = $buscador->contextoParaReclamo(
            $request->user()->tenant_id,
            $datos['patente'] ?? null,
            $datos['vin'] ?? null,
            CarbonImmutable::parse($datos['desde'])->startOfDay(),
            CarbonImmutable::parse($datos['hasta'])->endOfDay(),
        );

        return response()->json($contexto);
    }

    public function store(StoreReclamoRequest $request, Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('create', Reclamo::class);

        $datos = $request->validated();
        $usuario = $this->usuario($request);

        $folio = $datos['folio'] ?? $this->generarFolio($request->user()->tenant_id, $parqueadero->id);

        $reclamo = Reclamo::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'parqueadero_id' => $parqueadero->id,
            'estacionamiento_id' => $datos['estacionamiento_id'] ?? null,
            'registrado_por' => $usuario->id,
            'folio' => $folio,
            'estado' => EstadoReclamo::Abierto->value,
            'fecha_incidente' => $datos['fecha_incidente'],
            'hora_incidente' => $datos['hora_incidente'] ?? null,
            'patente_vista' => $datos['patente_vista'] ?? null,
            'cliente_nombre' => $datos['cliente_nombre'],
            'cliente_rut' => $datos['cliente_rut'] ?? null,
            'cliente_contacto' => $datos['cliente_contacto'] ?? null,
            'descripcion' => $datos['descripcion'],
            'foto_ruta' => $datos['foto_ruta'] ?? '',
            'foto_tomada_en' => ($datos['foto_ruta'] ?? null) !== null ? now() : null,
        ]);

        return response()->json(
            $this->presentar($reclamo->load('estacionamiento'), conFicha: true),
            201
        );
    }

    public function show(Reclamo $reclamo): JsonResponse
    {
        Gate::authorize('view', $reclamo);

        $conFicha = Gate::allows('verFichaCompleta', $reclamo);

        return response()->json(
            $this->presentar($reclamo->load('estacionamiento.lugar', 'estacionamiento.vehiculo'), conFicha)
        );
    }

    /**
     * Mueve el reclamo de estado.
     *
     * La transicion la valida `EstadoReclamo::puedeIrDesde()`, no el
     * controlador: es una regla del dominio y va en el enum, que es donde se
     * lee el mapa completo de transiciones. Un reclamo resuelto no vuelve a
     * "abierto" por un clic.
     *
     * Cerrar exige `resolucion` (lo pide el FormRequest) y lo anota en
     * bitacora: es la accion con mas consecuencia legal del modulo.
     */
    public function cambiarEstado(UpdateReclamoRequest $request, Reclamo $reclamo): JsonResponse
    {
        $nuevo = EstadoReclamo::from($request->validated('estado'));

        $cierra = $nuevo->estaCerrado();

        Gate::authorize($cierra ? 'resolver' : 'cambiarEstado', $reclamo);

        if (! $nuevo->puedeIrDesde($reclamo->estado)) {
            throw new ErrorDeParqueadero(
                sprintf(
                    'Un reclamo en estado "%s" no puede pasar a "%s".',
                    $reclamo->estado->etiqueta(),
                    $nuevo->etiqueta()
                ),
                'transicion_no_permitida',
            );
        }

        $reclamo->estado = $nuevo;

        if ($cierra) {
            $reclamo->resolucion = $request->validated('resolucion');
            $reclamo->resuelto_por = $request->user()->id;
            $reclamo->resuelto_en = now();
        }

        $reclamo->save();

        $this->bitacora->registrar(
            tenantId: $reclamo->tenant_id,
            usuario: $request->user(),
            ambito: 'reclamos',
            accion: $cierra ? 'resolver' : 'cambiar_estado',
            recursoTipo: Reclamo::class,
            recursoId: $reclamo->id,
            descripcion: sprintf('Reclamo %s: %s.', $reclamo->folio, $nuevo->etiqueta()),
            datos: [
                'folio' => $reclamo->folio,
                'estado_anterior' => $request->validated('estado'),
                'parqueadero_id' => $reclamo->parqueadero_id,
            ],
            direccionIp: $request->ip(),
        );

        return response()->json($this->presentar($reclamo->fresh(), conFicha: true));
    }

    /**
     * Folio corto y dictable por telefono: `R-000001`.
     *
     * El `id` interno es un UUID y no se puede leer en voz alta sin
     * equivocarce. El folio es unico por operador, y lo genera el sistema:
     * que lo escriba una persona es como aparecen dos reclamos con el mismo
     * numero.
     */
    private function generarFolio(string $tenantId, string $parqueaderoId): string
    {
        $ultimo = Reclamo::query()
            ->where('tenant_id', $tenantId)
            ->where('folio', 'like', 'R-%')
            ->orderByDesc('folio')
            ->value('folio');

        $siguiente = ((int) substr((string) $ultimo, 2)) + 1;

        return sprintf('R-%06d', $siguiente);
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->user();

        if (! $usuario instanceof Usuario) {
            abort(401);
        }

        return $usuario;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Reclamo $reclamo, bool $conFicha): array
    {
        $datos = [
            'id' => $reclamo->id,
            'folio' => $reclamo->folio,
            'parqueadero_id' => $reclamo->parqueadero_id,
            'estado' => $reclamo->estado->value,
            'estado_etiqueta' => $reclamo->estado->etiqueta(),
            'fecha_incidente' => $reclamo->fecha_incidente->toDateString(),
            'hora_incidente' => $reclamo->hora_incidente?->toIso8601String(),
            'patente_vista' => $reclamo->patente_vista,
            'tiene_foto' => $reclamo->foto_ruta !== '',
            'estacionamiento_id' => $reclamo->estacionamiento_id,
            'resuelto_en' => $reclamo->resuelto_en?->toIso8601String(),
        ];

        // El relato del cliente y su dato de contacto son lo mas sensible que
        // maneja el sistema. El listado no los trae.
        if ($conFicha) {
            $datos['cliente_nombre'] = $reclamo->cliente_nombre;
            $datos['cliente_rut'] = $reclamo->cliente_rut;
            $datos['cliente_contacto'] = $reclamo->cliente_contacto;
            $datos['descripcion'] = $reclamo->descripcion;
            $datos['foto_ruta'] = $reclamo->foto_ruta;
            $datos['resolucion'] = $reclamo->resolucion;
            $datos['vehiculo_en_la_sesion'] = $reclamo->estacionamiento?->vin;
        }

        return $datos;
    }
}