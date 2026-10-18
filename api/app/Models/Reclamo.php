<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoReclamo;
use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reclamo de un cliente.
 *
 * Un reclamo casi siempre empieza con "estuve estacionado el martes y no me
 * cobraron" o "me rebautizaron y no tengo registro". Para responder hace
 * falta el registro de ese dia, y por eso el reclamo guarda a que
 * `estacionamiento` apunta y a que hora fue el hecho.
 *
 * `folio` es un codigo corto y legible que se le da al cliente
 * ("R-2026-0001"), unico por operador. No es el `id` interno: el `id` es un
 * UUID que no se puede dictar por telefono.
 *
 * `patente_vista` es lo que el cliente dijo, que no siempre es lo que dice
 * la base. Se guarda aparte a proposito: la diferencia entre las dos es
 * justamente la pista del reclamo.
 *
 * `fecha_incidente` y `hora_incidente` estan separadas porque el cliente
 * recuerda "el martes a la tarde" y la busqueda en el registro se hace por
 * ventana de fechas. La hora puede quedar en `null` cuando el cliente no la
 * recuerda: se busca el dia completo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parqueadero_id
 * @property string|null $estacionamiento_id
 * @property string $registrado_por
 * @property string $folio
 * @property EstadoReclamo $estado
 * @property \Illuminate\Support\Carbon $fecha_incidente
 * @property \Illuminate\Support\Carbon|null $hora_incidente
 * @property string|null $patente_vista
 * @property string $cliente_nombre
 * @property string|null $cliente_rut
 * @property string|null $cliente_contacto
 * @property string $descripcion
 * @property string $foto_ruta
 * @property \Illuminate\Support\Carbon|null $foto_tomada_en
 * @property string|null $resolucion
 * @property string|null $resuelto_por
 * @property \Illuminate\Support\Carbon|null $resuelto_en
 */
class Reclamo extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'reclamos';

    protected $fillable = [
        'tenant_id',
        'parqueadero_id',
        'estacionamiento_id',
        'registrado_por',
        'folio',
        'estado',
        'fecha_incidente',
        'hora_incidente',
        'patente_vista',
        'cliente_nombre',
        'cliente_rut',
        'cliente_contacto',
        'descripcion',
        'foto_ruta',
        'foto_tomada_en',
        'resolucion',
        'resuelto_por',
        'resuelto_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoReclamo::class,
            'fecha_incidente' => 'immutable_date',
            'hora_incidente' => 'immutable_datetime',
            'foto_tomada_en' => 'immutable_datetime',
            'resuelto_en' => 'immutable_datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function parqueadero(): BelongsTo
    {
        return $this->belongsTo(Parqueadero::class);
    }

    public function estacionamiento(): BelongsTo
    {
        return $this->belongsTo(Estacionamiento::class);
    }

    public function registradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }

    public function resueltoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'resuelto_por');
    }
}