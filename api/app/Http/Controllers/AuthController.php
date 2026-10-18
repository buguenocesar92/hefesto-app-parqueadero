<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Acceso y salida del operador.
 *
 * EL PARQUEADERO NO GUARDA CONTRASENAS
 * ------------------------------------
 * La identidad la resuelve Authentik. Esta API solo decide que puede hacer
 * quien entra, leyendo el rol que tiene en `usuarios`.
 *
 * POR QUE NO HAY UNA PANTALLA DE CONTRASENA
 * ----------------------------------------
 * Un formulario de acceso propio obligaria al sistema a guardar algo que
 * permita recuperar una contrasena, y en un sistema donde se ven placas,
 * nombres y RUT, ese algo es el activo mas robado que existe. El acceso
 * delegando en el proveedor de identidad deja esa responsabilidad donde ya
 * hay gente que la sabe tomar.
 *
 * LA EMPRESA SE RESUELVE SOLA
 * ---------------------------
 * Se busca por el dominio del correo de la cuenta, no por un selector de
 * empresa. Un selector es el lugar donde la gente se equivoca, y equivocarse
 * de empresa en un sistema con datos de clientes es exactamente el tipo de
 * error que el aislamiento no puede tapar despues.
 */
class AuthController extends Controller
{
    /** Quien soy, con lo que la interfaz necesita para Armar el menu. */
    public function yo(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if (! $usuario instanceof Usuario) {
            return response()->json(['mensaje' => 'No hay sesion abierta.'], 401);
        }

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'rol' => $usuario->rol->value,
            'rol_etiqueta' => $usuario->rol->etiqueta(),
            'empresa' => $usuario->empresa?->nombre,
            'empresa_id' => $usuario->tenant_id,
            'ultimo_ingreso' => $usuario->ultimo_ingreso?->toIso8601String(),
            'puede_operar' => $usuario->rol->puedeOperar(),
            'puede_cobrar' => $usuario->rol->puedeCobrar(),
            'puede_administrar' => $usuario->rol->puedeAdministrar(),
        ]);
    }

    /** Cierra la sesion local. */
    public function salir(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario instanceof Usuario) {
            // La marca queda aunque la sesion se cierre: "ultimo_ingreso" es
            // la ultima vez que entro, no la ultima vez que hubo una peticion.
            $usuario->ultimo_ingreso = now();

            $usuario->save();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesion cerrada.']);
    }

    /**
     * Se llama al cerrar la sesion de Authentik, no al entrar.
     *
     * Si no hay sesion local se responde con exito igual: el cierre de
     * sesion es idempotente, y fallar aca dejaria al operador con una
     * pantalla de error que no puede arreglar.
     */
    public function callback(Request $request): JsonResponse
    {
        return response()->json(['mensaje' => 'Sesion cerrada.']);
    }

    /**
     * Cuentas del operador, para que el administrador de la sede vea quien
     * tiene acceso.
     */
    public function cuentas(Request $request): JsonResponse
    {
        if (! $request->user()?->rol->puedeAdministrar()) {
            abort(404);
        }

        $cuentas = Usuario::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy('nombre')
            ->get()
            ->map(fn (Usuario $usuario): array => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'correo' => $usuario->correo,
                'rol' => $usuario->rol->value,
                'rol_etiqueta' => $usuario->rol->etiqueta(),
                'activo' => $usuario->activo,
                'ultimo_ingreso' => $usuario->ultimo_ingreso?->toIso8601String(),
            ]);

        return response()->json(['cuentas' => $cuentas]);
    }

    /**
     * Diagnostico del contexto de operador.
     *
     * Existe para responder una pregunta muy concreta en soporte: "esta
     * consulta esta viendo los datos de la empresa correcta?". Responde si
     * hay contexto y cual es, y nada mas: no devuelve datos de negocio.
     */
    public function contexto(Request $request): JsonResponse
    {
        $contexto = new ContextoTenant();

        $enPeticion = $contexto->tenantIdSolicitado();
        $enConexion = $contexto->tenantIdEnConexion();

        $sede = Tenant::query()->where('id', $enPeticion)->first();

        return response()->json([
            'operador_solicitado' => $enPeticion,
            'operador_en_conexion' => $enConexion,
            'coinciden' => $enPeticion !== null && $enPeticion === $enConexion,
            'en_transaccion' => DB::transactionLevel() >= 1,
            'nombre' => $sede?->nombre,
        ]);
    }

    /**
     * Comprobacion de vida de la sesion y de la base.
     *
     * Es distinta de la salud del servicio: esta necesita sesion y responde
     * con detalle. La salud no necesita sesion y por eso la puede correr el
     * orquestador cuando todo esta caido.
     */
    public function sesion(Request $request): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable $error) {
            return response()->json([
                'estado' => 'degradado',
                'detalle' => 'La base no responde: ' . $error->getMessage(),
            ], 503);
        }

        return response()->json([
            'estado' => 'ok',
            'usuario' => $request->user()?->correo,
        ]);
    }
}