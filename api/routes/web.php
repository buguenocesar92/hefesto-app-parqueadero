<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoletaController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\IngresoController;
use App\Http\Controllers\LugarController;
use App\Http\Controllers\MensualidadController;
use App\Http\Controllers\MovimientoController;
use App\Http\Controllers\OcupacionController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\ParqueaderoController;
use App\Http\Controllers\ReclamoController;
use App\Http\Controllers\SalidaController;
use App\Http\Controllers\SaludController;
use App\Http\Controllers\TarifaController;
use App\Http\Controllers\VehiculoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de la API de estacionamientos
|--------------------------------------------------------------------------
|
| Estructura: /api/v1/{recurso}
|
| TODO lo que este protegido cuelga de un middleware de sesion. La unica
| excepcion es `api/v1/salud`, que no pide credenciales a proposito: un
| chequeo de salud que necesita sesion no lo puede correr el orquestador
| justo cuando el servicio esta caido.
|
| EL AISLAMIENTO NO ESTA EN LAS RUTAS
| -----------------------------------
| Cada rutacuelga del grupo `web`, donde `ResolverTenant` envuelve la
| peticion en una transaccion con el operador fijado y la politica RLS de
| PostgreSQL filtra lo que se lee y lo que se escribe. No hay ningun
| `->middleware('tenant:...')` que se pueda olvidar en una ruta nueva.
|
| LOS RECURSOS CON ID PROPIO
| --------------------------
| Las rutas que llevan `{algo}` usan el enlace implicito de Laravel: si el id
| pertenece a otro operador, el modelo no aparece y la respuesta es 404. No
| hay que escribir esa comprobacion en cada controlador.
|
*/

Route::prefix('api/v1')->group(function (): void {

    // --- Salud -----------------------------------------------------------
    // Publica y sin sesion. La corre el orquestador.
    Route::get('salud', SaludController::class)->name('salud');

    // --- Sesion ----------------------------------------------------------
    Route::get('yo', [AuthController::class, 'yo'])->name('yo');
    Route::post('salir', [AuthController::class, 'salir'])->name('salir');
    Route::get('sesion', [AuthController::class, 'sesion'])->name('sesion');

    // Diagnostico del contexto de operador. Responde "esta peticion esta
    // viendo los datos de la empresa correcta?". Es de las primeras cosas que
    // se corre en soporte, y dice la verdad sobre la RLS.
    Route::get('contexto', [AuthController::class, 'contexto'])->name('contexto');

    Route::get('cuentas', [AuthController::class, 'cuentas'])->name('cuentas');

    // --- Sedes -----------------------------------------------------------
    Route::get('sedes', [ParqueaderoController::class, 'index'])->name('sedes.index');
    Route::get('sedes/{parqueadero}', [ParqueaderoController::class, 'show'])->name('sedes.show');

    // --- Panel -----------------------------------------------------------
    Route::get('panel', [PanelController::class, 'index'])->name('panel');
    Route::get('sedes/{parqueadero}/panel', [PanelController::class, 'show'])->name('sedes.panel');

    // --- Ocupacion -------------------------------------------------------
    // Se lee antes de dejar pasar un vehiculo, asi que va antes que el resto
    // del dominio en el archivo: es la pantalla mas usada del sistema.
    Route::get('sedes/{parqueadero}/ocupacion', [OcupacionController::class, 'estado'])
        ->name('ocupacion');
    Route::get('sedes/{parqueadero}/ocupacion/lugares', [OcupacionController::class, 'lugares'])
        ->name('ocupacion.lugares');

    // --- Lugares ---------------------------------------------------------
    Route::get('sedes/{parqueadero}/lugares', [LugarController::class, 'index'])
        ->name('lugares.index');
    Route::post('sedes/{parqueadero}/lugares', [LugarController::class, 'store'])
        ->name('lugares.store');
    Route::put('lugares/{lugar}', [LugarController::class, 'update'])->name('lugares.update');
    Route::delete('lugares/{lugar}', [LugarController::class, 'desactivar'])
        ->name('lugares.desactivar');

    // --- Tarifas ---------------------------------------------------------
    Route::get('sedes/{parqueadero}/tarifas', [TarifaController::class, 'index'])
        ->name('tarifas.index');
    Route::get('sedes/{parqueadero}/tarifas/vigentes', [TarifaController::class, 'vigentes'])
        ->name('tarifas.vigentes');
    Route::post('sedes/{parqueadero}/tarifas', [TarifaController::class, 'store'])
        ->name('tarifas.store');
    Route::put('tarifas/{tarifa}', [TarifaController::class, 'update'])->name('tarifas.update');
    Route::delete('tarifas/{tarifa}', [TarifaController::class, 'desactivar'])
        ->name('tarifas.desactivar');

    // --- Ingreso y salida ------------------------------------------------
    // El camino de escritorio: revisar el acceso ANTES de marcar el ingreso.
    Route::post('ingresos/revisar', [IngresoController::class, 'revisar'])->name('ingresos.revisar');
    Route::post('ingresos', [IngresoController::class, 'store'])->name('ingresos.store');

    // Buscar sin cobrar, para el cliente que perdio el ticket.
    Route::post('salidas/buscar', [SalidaController::class, 'buscar'])->name('salidas.buscar');
    Route::post('salidas', [SalidaController::class, 'store'])->name('salidas.store');
    Route::post('estacionamientos/{estacionamiento}/salida', [SalidaController::class, 'store'])
        ->name('estacionamientos.salida');
    Route::post('estacionamientos/{estacionamiento}/reconstruir', [SalidaController::class, 'reconstruir'])
        ->name('estacionamientos.reconstruir');

    // --- Registro --------------------------------------------------------
    Route::get('sedes/{parqueadero}/movimientos', [MovimientoController::class, 'historial'])
        ->name('movimientos.index');
    Route::get('estacionamientos/{estacionamiento}/movimientos', [MovimientoController::class, 'deLaSesion'])
        ->name('estacionamientos.movimientos');
    Route::post('movimientos/{movimiento}/anotaciones', [MovimientoController::class, 'anotar'])
        ->name('movimientos.anotar');

    // --- Boletas ---------------------------------------------------------
    Route::get('sedes/{parqueadero}/boletas', [BoletaController::class, 'index'])
        ->name('boletas.index');
    Route::get('sedes/{parqueadero}/boletas/total', [BoletaController::class, 'totalDelDia'])
        ->name('boletas.total');
    Route::get('boletas/{boleta}', [BoletaController::class, 'show'])->name('boletas.show');
    Route::get('boletas/{boleta}/detalle', [BoletaController::class, 'detalle'])
        ->name('boletas.detalle');
    Route::post('boletas/{boleta}/anular', [BoletaController::class, 'anular'])
        ->name('boletas.anular');

    // --- Mensualidades ---------------------------------------------------
    Route::get('sedes/{parqueadero}/mensualidades', [MensualidadController::class, 'index'])
        ->name('mensualidades.index');
    Route::get('mensualidades/por-cobrar', [MensualidadController::class, 'porCobrar'])
        ->name('mensualidades.por-cobrar');
    Route::get('mensualidades/{mensualidad}', [MensualidadController::class, 'show'])
        ->name('mensualidades.show');
    Route::post('sedes/{parqueadero}/mensualidades', [MensualidadController::class, 'store'])
        ->name('mensualidades.store');
    Route::post('mensualidades/{mensualidad}/pagos', [MensualidadController::class, 'registrarPago'])
        ->name('mensualidades.pagos');
    Route::put('mensualidades/{mensualidad}/estado', [MensualidadController::class, 'cambiarEstado'])
        ->name('mensualidades.estado');

    // --- Reclamos --------------------------------------------------------
    Route::get('sedes/{parqueadero}/reclamos', [ReclamoController::class, 'index'])
        ->name('reclamos.index');
    Route::get('reclamos/contexto', [ReclamoController::class, 'contexto'])
        ->name('reclamos.contexto');
    Route::get('reclamos/{reclamo}', [ReclamoController::class, 'show'])->name('reclamos.show');
    Route::post('sedes/{parqueadero}/reclamos', [ReclamoController::class, 'store'])
        ->name('reclamos.store');
    Route::put('reclamos/{reclamo}/estado', [ReclamoController::class, 'cambiarEstado'])
        ->name('reclamos.estado');

    // --- Vehiculos -------------------------------------------------------
    Route::get('vehiculos', [VehiculoController::class, 'index'])->name('vehiculos.index');
    Route::get('vehiculos/{vehiculo}', [VehiculoController::class, 'show'])->name('vehiculos.show');

    // --- Configuracion ---------------------------------------------------
    Route::get('configuracion', [ConfiguracionController::class, 'show'])
        ->name('configuracion.show');
    Route::put('configuracion', [ConfiguracionController::class, 'update'])
        ->name('configuracion.update');

});