<?php

declare(strict_types=1);

namespace App\Services\Boletas;

use App\Models\Boleta;
use App\Models\Estacionamiento;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Services\Estacionamiento\RelojDelServidor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reemite la boleta de un estacionamiento que ya salio.
 *
 * POR QUE EXISTE
 * --------------
 * El ticket que se entrega al salir es el respaldo del cliente. Si lo pierde,
 * el operador necesita poder demostrar que ya cobro, y no hay forma de hacer
 * eso si la boleta solo existe en un papel que nadie tiene.
 *
 * Es el mismo cobro, no uno nuevo: sale de los mismos numeros que quedaron
 * guardados en `estacionamientos`, con la misma tarifa que estaba vigente el
 * dia del ingreso. Por eso emite una boleta NUEVA con folio nuevo y deja la
 * anterior como estaba: las dos dicen lo mismo, y eso es lo que permite
 * responder "y por que tengo dos tickets".
 *
 * LO QUE NO ES
 * -------------
 * No es un reemito automatico, no es una nota de credito y no borra nada. Si
 * el cobro estaba mal, la respuesta es anular la boleta (`BoletaController`),
 * que deja constancia del error; esto solo sirve cuando el cobro estaba bien
 * y lo que falta es el papel.
 *
 * LA TARIFA
 * ---------
 * Se busca la que estaba vigente el DIA DEL INGRESO, igual que en el cobro
 * normal. Si el precio subio despues, la cuenta se rehace con el precio de
 * entonces: es la unica forma de que el ticket perdido y el ticket original
 * digan la misma cifra.
 *
 * NOTA DE MANTENIMIENTO
 * ---------------------
 * La busqueda de tarifa vigente esta escrita tambien, en privado, dentro de
 * `RegistradorSalida::tarifaVigente()`. Se dejo aparte en vez de cambiar ese
 * servicio ya escrito, pero son dos copias de la misma regla y conviene
 * reunirlas. Queda anotado en docs/10-PRUEBAS-Y-ESTADO.md.
 */
class ReconstructorDeBoleta
{
    public function __construct(private readonly GeneradorDeBoleta $generador) {}

    public function reconstruir(
        Estacionamiento $sesion,
        Usuario $operador,
        string $formaPago = 'efectivo',
    ): Boleta {
        if ($sesion->salida_en === null) {
            throw new RuntimeException(
                'Ese vehiculo todavia esta adentro. Use el cobro normal, no la reconstruccion.'
            );
        }

        if ($sesion->boleta_id !== null) {
            throw new RuntimeException(
                'Ese estacionamiento ya tiene boleta emitida. Reimprima la existente en vez de emitir otra.'
            );
        }

        if ($sesion->segundos_permanencia === null) {
            throw new RuntimeException(
                'Ese estacionamiento no tiene tiempo de permanencia registrado, asi que no hay nada que reconstruir. '
                . 'Revise el registro de movimientos.'
            );
        }

        $tarifa = $this->tarifaVigente($sesion, $sesion->vehiculo?->tipo_vehiculo?->value);

        return DB::transaction(function () use ($sesion, $operador, $tarifa, $formaPago): Boleta {
            // Se vuelve a leer con bloqueo de fila: si otro operador esta
            // reemitiendo el mismo estacionamiento a la vez, uno de los dos
            // tiene que fallar en vez de emitir dos boletas nuevas.
            $bloqueada = Estacionamiento::query()->lockForUpdate()->findOrFail($sesion->id);

            if ($bloqueada->boleta_id !== null) {
                throw new RuntimeException(
                    'Ese estacionamiento recien recibio una boleta reemitida. Imprima la que existe.'
                );
            }

            $boleta = $this->generador->reconstruirDesdeRegistro(
                sesion: $bloqueada,
                operador: $operador,
                tarifa: $tarifa,
                formaPago: $formaPago,
            );

            $bloqueada->boleta_id = $boleta->id;
            $bloqueada->save();

            return $boleta->load('estacionamiento.lugar');
        });
    }

    /**
     * Tarifa que estaba vigente el dia del ingreso.
     *
     * La misma regla del cobro normal: la mas reciente que cubria esa
     * fecha, activa, y que sirva al tipo de vehiculo o venga sin filtro.
     */
    private function tarifaVigente(Estacionamiento $sesion, ?string $tipoVehiculo): Tarifa
    {
        $consulta = Tarifa::query()
            ->where('parqueadero_id', $sesion->parqueadero_id)
            ->where('activo', true)
            ->where('vigente_desde', '<=', $sesion->ingreso_en)
            ->where(function ($interna) use ($sesion): void {
                $interna->whereNull('vigente_hasta')
                    ->orWhere('vigente_hasta', '>=', $sesion->ingreso_en);
            })
            ->orderByDesc('vigente_desde');

        if ($tipoVehiculo !== null) {
            $consulta->where(function ($interna) use ($tipoVehiculo): void {
                $interna->whereNull('tipo_vehiculo')
                    ->orWhere('tipo_vehiculo', $tipoVehiculo);
            });
        }

        $tarifa = $consulta->first();

        if ($tarifa === null) {
            throw new RuntimeException(
                'No hay ninguna tarifa vigente en esa sede para el '
                . $sesion->ingreso_en->format('d-m-Y')
                . '. Para reconstruir la cuenta hay que cargar la tarifa que rigio ese dia, '
                . 'aunque hoy ya no este activa. Revise la pantalla de tarifas.'
            );
        }

        if (! $tarifa->estaCompleta()) {
            throw new RuntimeException(
                'La tarifa "' . $tarifa->nombre . '" esta incompleta: le falta los minutos por fraccion. '
                . 'Sin ese dato no se puede reconstruir la cuenta.'
            );
        }

        return $tarifa;
    }

    /** Instante de referencia para los mensajes. */
    public function ahora(): string
    {
        return RelojDelServidor::ahora()->toIso8601String();
    }
}