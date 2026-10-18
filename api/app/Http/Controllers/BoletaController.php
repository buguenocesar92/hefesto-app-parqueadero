<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AnularBoletaRequest;
use App\Models\Boleta;
use App\Models\Estacionamiento;
use App\Models\Parqueadero;
use App\Services\Auditoria\RegistradorDeBitacora;
use App\Services\Estacionamiento\CalculadoraTarifa;
use App\Services\Estacionamiento\RelojDelServidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Boletas: el dinero.
 *
 * CUATRO OPERACIONES Y NADA MAS
 * -----------------------------
 *  - listado del dia, para el cierre de turno;
 *  - ver una boleta, para reimprimir el ticket;
 *  - anular una boleta, con motivo escrito;
 *  - el total del dia, que es la cuenta que se entrega al operador al cierre.
 *
 * Emitir boletas NO esta aca: se emite dentro de la salida, en la misma
 * transaccion que cierra el estacionamiento. Si la boleta y la salida
 * pudieran quedar separadas, un corte de luz entre las dos deja un vehiculo
 * afuera del registro con un cobro hecho.
 *
 * LO QUE ESTA PROHIBIDO
 * ---------------------
 * No hay endpoint para editar el total ni el desglose de una boleta. Si el
 * cobro estaba mal, la unica salida es anularla con su motivo y cobrar de
 * nuevo. Un total que se puede editar no es un documento, es un campo.
 */
class BoletaController extends Controller
{
    public function __construct(private readonly RegistradorDeBitacora $bitacora) {}

    /** Boletas del dia de una sede. */
    public function index(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Boleta::class);

        $hoy = Carbon::now();

        $boletas = Boleta::query()
            ->where('parqueadero_id', $parqueadero->id)
            ->whereBetween('emitida_en', [$hoy->startOfDay(), $hoy->endOfDay()])
            ->orderByDesc('emitida_en')
            ->limit(500)
            ->get()
            ->map(fn (Boleta $boleta): array => $this->presentar($boleta));

        return response()->json([
            'boletas' => $boletas,
            'total_del_dia' => $this->totalDelDia($parqueadero->id, $hoy),
        ]);
    }

    public function show(Boleta $boleta): JsonResponse
    {
        Gate::authorize('verDetalle', $boleta);

        return response()->json($this->presentar($boleta->load('estacionamiento.lugar')));
    }

    /**
     * La cuenta del dia, hecha en la base.
     *
     * Se usa `CalculadoraTarifa::totalDelDia()`, que suma en PostgreSQL y no
     * trae las filas a PHP. Con mil boletas en un turno, traerlas para sumarlas
     * en el servidor de la aplicacion es trabajo de sobra para un numero que
     * se pide varias veces por turno.
     */
    public function totalDelDia(Parqueadero $parqueadero): JsonResponse
    {
        Gate::authorize('viewAny', Boleta::class);

        $hoy = Carbon::now();

        $ventana = [
            $hoy->copy()->startOfDay()->toDateTimeString(),
            $hoy->copy()->endOfDay()->toDateTimeString(),
        ];

        return response()->json([
            'parqueadero_id' => $parqueadero->id,
            'fecha' => $hoy->toDateString(),
            ...app(CalculadoraTarifa::class)->totalDelDia($parqueadero->id, ...$ventana),
        ]);
    }

    /**
     * Anula una boleta.
     *
     * NO borra y NO cambia el total. Pasa a `anulada` y guarda el motivo. La
     * fila sigue estando ahi con el numero que se cobro, y eso es lo que
     * permite que el total del dia cuadre y que la anulacion sea auditable.
     *
     * Se anota en bitacora con el folio y el motivo: es una de las pocas
     * acciones del sistema que queda en los dos sitios, el documento y el
     * registro de acciones.
     */
    public function anular(AnularBoletaRequest $request, Boleta $boleta): JsonResponse
    {
        Gate::authorize('anular', $boleta);

        if (! $boleta->estaEmitida()) {
            return response()->json([
                'mensaje' => 'Esa boleta ya esta anulada.',
                'codigo' => 'boleta_ya_anulada',
            ], 422);
        }

        $boleta->estado = Boleta::ESTADO_ANULADA;
        $boleta->anulacion_motivo = $request->validated('motivo');
        $boleta->save();

        $this->bitacora->registrar(
            tenantId: $boleta->tenant_id,
            usuario: $request->user(),
            ambito: 'boletas',
            accion: 'anular',
            recursoTipo: Boleta::class,
            recursoId: $boleta->id,
            descripcion: sprintf('Boleta %s anulada.', $boleta->folio),
            datos: [
                'folio' => $boleta->folio,
                'total' => $boleta->total,
                'parqueadero_id' => $boleta->parqueadero_id,
            ],
            direccionIp: $request->ip(),
        );

        return response()->json($this->presentar($boleta->fresh()));
    }

    /**
     * El desglose completo, tal como salio al momento del cobro.
     *
     * Es lo que se guarda el `desglose` en la boleta y por que esa columna
     * existe: si la tarifa cambia manana, este detalle sigue explicando el
     * cobro de ayer.
     */
    public function detalle(Boleta $boleta): JsonResponse
    {
        Gate::authorize('verDetalle', $boleta);

        return response()->json([
            'folio' => $boleta->folio,
            'emitida_en' => $boleta->emitida_en->toIso8601String(),
            'desglose' => $boleta->desglose,
            'subtotal' => $boleta->subtotal,
            'descuento' => $boleta->descuento,
            'total' => $boleta->total,
            'estado' => $boleta->estado,
            'anulacion_motivo' => $boleta->anulacion_motivo,
            'generado_el' => RelojDelServidor::ahora()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Boleta $boleta): array
    {
        return [
            'id' => $boleta->id,
            'folio' => $boleta->folio,
            'parqueadero_id' => $boleta->parqueadero_id,
            'estacionamiento_id' => $boleta->estacionamiento_id,
            'emitida_en' => $boleta->emitida_en->toIso8601String(),
            'subtotal' => $boleta->subtotal,
            'descuento' => $boleta->descuento,
            'total' => $boleta->total,
            'forma_pago' => $boleta->forma_pago,
            'estado' => $boleta->estado,
            'anulacion_motivo' => $boleta->anulacion_motivo,
            'patente' => $boleta->estacionamiento?->patente,
            'lugar_codigo' => $boleta->estacionamiento?->lugar?->codigo,
        ];
    }
}