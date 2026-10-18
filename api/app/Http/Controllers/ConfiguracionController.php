<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateConfiguracionRequest;
use App\Models\Configuracion;
use App\Models\Usuario;
use App\Services\Auditoria\RegistradorDeBitacora;
use App\Services\Estacionamiento\RelojDelServidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Configuracion del operador: las reglas con las que se cobra y se deja
 * entrar.
 *
 * UNA FILA POR OPERADOR
 * ---------------------
 * El indice unico sobre `configuraciones.tenant_id` lo garantiza, asi que no
 * hay que elegir cual es la buena. Si no existe todavia, se crea con los
 * valores por omision al abrir la pantalla.
 *
 * LO QUE SE GUARDA Y LO QUE HOY NO SE USA
 * --------------------------------------
 * `minutos_cortesia` y `minutos_redondeo` se guardan y se muestran, pero el
 * cobro NO los lee: `CalculadoraTarifa` usa `tarifas.minutos_incluidos` y
 * `tarifas.minutos_por_fraccion`, que son por tarifa.
 *
 * No se corrigio el calculo para que los usara porque eso cambiaria el monto
 * que cobran los estacionamientos que ya tienen boletas, y cambiar un cobro
 * en curso sin avisar es exactamente lo que este sistema evita. Queda como
 * decision pendiente del operador. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private readonly RegistradorDeBitacora $bitacora) {}

    public function show(): JsonResponse
    {
        $usuario = $this->usuario();

        $configuracion = Configuracion::delOperador($usuario->tenant_id);

        Gate::authorize('view', $configuracion);

        return response()->json($this->presentar($configuracion));
    }

    public function update(UpdateConfiguracionRequest $request): JsonResponse
    {
        $usuario = $this->usuario();

        $configuracion = Configuracion::delOperador($usuario->tenant_id);

        Gate::authorize('update', $configuracion);

        $antes = $configuracion->only([
            'mensualidad_vencida_bloquea_acceso',
            'minutos_cortesia',
            'minutos_redondeo',
            'dias_aviso_vencimiento',
        ]);

        $configuracion->fill($request->validated())->save();

        $despues = $configuracion->fresh()->only(array_keys($antes));

        $this->bitacora->registrar(
            tenantId: $usuario->tenant_id,
            usuario: $usuario,
            ambito: 'configuracion',
            accion: 'modificar',
            recursoTipo: Configuracion::class,
            recursoId: $configuracion->id,
            descripcion: 'Se cambio la configuracion del operador.',
            datos: ['antes' => $antes, 'despues' => $despues],
            direccionIp: $request->ip(),
        );

        return response()->json($this->presentar($configuracion->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Configuracion $configuracion): array
    {
        return [
            'mensualidad_vencida_bloquea_acceso' => $configuracion->mensualidad_vencida_bloquea_acceso,
            'minutos_cortesia' => $configuracion->minutos_cortesia,
            'minutos_redondeo' => $configuracion->minutos_redondeo,
            'zona_horaria' => $configuracion->zona_horaria,
            'dias_aviso_vencimiento' => $configuracion->dias_aviso_vencimiento,
            // Se declara explicitamente para que la interfaz no lo prometa.
            'minutos_cortesia_en_uso' => false,
            'hora_del_servidor' => RelojDelServidor::ahora()->toIso8601String(),
        ];
    }

    private function usuario(): Usuario
    {
        $usuario = request()->user();

        if (! $usuario instanceof Usuario) {
            abort(401);
        }

        return $usuario;
    }
}