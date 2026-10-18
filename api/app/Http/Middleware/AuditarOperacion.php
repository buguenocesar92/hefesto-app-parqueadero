<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Services\Auditoria\RegistradorDeBitacora;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Deja constancia de las acciones que cambian algo.
 *
 * QUE SE ESCRIBE Y QUE NO
 * -----------------------
 * Se anotan las operaciones que mueven datos: crear o modificar un recurso,
 * anular una boleta, cambiar el estado de un reclamo. No se anotan las
 * lecturas, porque en un turno de ocho horas el mostrador hace cientos y la
 * bitacora dejaria de servir para nada.
 *
 * QUE PASA SI LA FALLA
 * --------------------
 * NUNCA se corta la operacion. Si la bitacora falla, el error se registra y
 * la peticion sigue: el vehiculo esta adentro del estacionamiento y eso no
 * se puede deshacer por un problema de auditoria. Un|Reclamo sin su registro
 * es un problema de mala suerte; un vehiculo que quedo afuera de la base es
 * un problema de seguridad.
 *
 * ORDEN DE LOS MIDDLEWARE
 * -----------------------
 * Este va DESPUES de `ResolverTenant`, asi que corre dentro de la
 * transaccion con el operador ya fijado y la politica RLS lo deja escribir
 * en `bitacora`.
 */
class AuditarOperacion
{
    /** Rutas que nunca se anotan. */
    private const RUTAS_EXCLUIDAS = [
        'api/v1/salud',
        'sanctum/csrf-cookie',
    ];

    public function __construct(private readonly RegistradorDeBitacora $bitacora) {}

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if (! $request->isMethod('POST', 'PUT', 'PATCH', 'DELETE')) {
            return $respuesta;
        }

        if ($this->rutaExcluida($request)) {
            return $respuesta;
        }

        $usuario = $request->user();

        if (! $usuario instanceof Usuario) {
            return $respuesta;
        }

        // Una peticion que ya fallo la dejo registrada el controlador que la
        // trato: duplicarla en la bitacora seria ruido.
        if ($respuesta->getStatusCode() >= 400) {
            return $respuesta;
        }

        try {
            $this->bitacora->registrar(
                tenantId: $usuario->tenant_id,
                usuario: $usuario,
                ambito: $this->ambito($request),
                accion: $this->accion($request),
                recursoTipo: $this->recurso($request),
                recursoId: $this->recursoId($request),
                descripcion: sprintf(
                    '%s en %s',
                    $this->accion($request),
                    $request->path()
                ),
                datos: [
                    'metodo' => $request->method(),
                    'ruta' => $request->path(),
                ],
                direccionIp: $request->ip(),
            );
        } catch (Throwable $error) {
            report($error);
        }

        return $respuesta;
    }

    private function rutaExcluida(Request $request): bool
    {
        $ruta = 'api/' . ltrim($request->path(), '/');

        foreach (self::RUTAS_EXCLUIDAS as $excluida) {
            if (str_starts_with($ruta, $excluida)) {
                return true;
            }
        }

        return false;
    }

    /** El ambito es el segundo segmento: el nombre de la recurso. */
    private function ambito(Request $request): string
    {
        $segmentos = explode('/', $request->path());

        return mb_substr((string) ($segmentos[1] ?? 'general'), 0, 32);
    }

    private function accion(Request $request): string
    {
        return match ($request->method()) {
            'POST' => 'crear',
            'PUT', 'PATCH' => 'modificar',
            'DELETE' => 'eliminar',
            default => 'otra',
        };
    }

    /** El recurso se saca del patron de la ruta, no del cuerpo. */
    private function recurso(Request $request): string
    {
        $segmentos = explode('/', $request->path());

        return mb_substr((string) ($segmentos[1] ?? 'desconocido'), 0, 48);
    }

    private function recursoId(Request $request): ?string
    {
        $segmentos = explode('/', $request->path());

        // Rutas con {recurso}/{id}: el id es un UUID.
        $candidato = $segmentos[2] ?? null;

        if ($candidato === null || ! preg_match('/^[0-9a-fA-F-]{36}$/', $candidato)) {
            return null;
        }

        return $candidato;
    }
}