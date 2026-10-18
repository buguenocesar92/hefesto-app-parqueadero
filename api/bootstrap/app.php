<?php

declare(strict_types=1);

use App\Exceptions\ErrorDeParqueadero;
use App\Http\Middleware\AuditarOperacion;
use App\Http\Middleware\ResolverTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Punto de arranque de la API de Gestion de Estacionamientos.
 *
 * Nota sobre el esqueleto: este archivo reemplaza al que genera
 * `composer create-project laravel/laravel`. El resto del esqueleto
 * (artisan, public/index.php, storage, etc.) lo genera Composer.
 * Ver docs/04-INSTALACION.md, paso 2.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF y sesion se aplican por el grupo `web` de Laravel.
        // La interfaz la sirve Nuxt, que actua como BFF: la cookie de sesion
        // viaja en el mismo origen y el token CSRF se reenvia como cabecera
        // X-XSRF-TOKEN. Ver docs/07-SEGURIDAD.md.
        $middleware->web(append: [
            // El orden importa: `ResolverTenant` envuelve la peticion en una
            // transaccion con el operador ya fijado, y `AuditarOperacion`
            // corre DENTRO de ella. Si se invirtieran, la bitacora se
            // escribiria sin contexto y la RLS la rechazaria.
            ResolverTenant::class,
            AuditarOperacion::class,
        ]);

        $middleware->alias([
            'tenant.resuelto' => ResolverTenant::class,
        ]);

        // El chequeo de salud es publico y lo corre el orquestador sin
        // credenciales. No debe exigir token CSRF.
        $middleware->validateCsrfTokens(except: [
            'api/v1/salud',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un error de autorizacion entre operadores no debe revelar si el
        // recurso existe en el otro. Se responde 404, no 403.
        $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });

        // Los errores de dominio del parqueadero (lugar tomado, boleta ya
        // anulada, reclamo en un estado que no admite el salto) se devuelven
        // como 422 con un codigo estable, para que la interfaz pueda reaccionar
        // sin adivinar. Ver docs/09-API.md.
        $exceptions->render(function (ErrorDeParqueadero $e, $request) {
            return response()->json([
                'mensaje' => $e->getMessage(),
                'codigo' => $e->codigo(),
            ], 422);
        });

        // Una violacion de la politica `WITH CHECK` de PostgreSQL significa
        // que se intento escribir en nombre de otro operador. Es un fallo de
        // programacion o un intento deliberado: en los dos casos la respuesta
        // correcta es 422 y no un 500 con el detalle de la base.
        $exceptions->render(function (Illuminate\Database\QueryException $e, $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            // 42501 es insufficient_privilege, que es lo que lanza
            // `public.app_tenant_id()` cuando no hay contexto de operador.
            if ((string) $e->getCode() === '42501') {
                return response()->json([
                    'mensaje' => 'La operacion no tiene contexto de operador. Se aborta para no exponer datos de otro operador.',
                    'codigo' => 'sin_contexto_operador',
                ], 422);
            }

            return null;
        });
    })
    ->create();