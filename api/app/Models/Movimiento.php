<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimiento;
use App\Enums\TipoVehiculo;
use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un hecho en el registro del estacionamiento. Es la fila mas importante del
 * sistema: el ticket que se entrega al salir, la evidencia de un reclamo y
 * el historico que explica una boleta salen de aca.
 *
 * COPIA LITERAL, NO REFERENCIA
 * ---------------------------
 * `patente`, `vin`, `tipo_vehiculo` y `lugar_codigo` NO son Llaves foraneas:
 * son el valor que se escribio ese dia. La incoherencia con `vehiculos` es
 * imposible por construccion, porque el servicio copia los dos en la misma
 * transaccion.
 *
 * La razon esta en la migracion 000010: si alguien corrige la patente de un
 * vehiculo, los movimientos antiguos deben seguir mostrando la patente que
 * se vio ese dia. Un reclamo se resuelve mirando el registro, y el registro
 * no se reescribe.
 *
 * NO SE EDITA
 * -----------
 * Un movimiento no se actualiza ni se borra. Para corregir algo se escribe
 * un movimiento de anulacion y una anotacion. Eso no es solo una convencion
 * del codigo: la migracion 000017 levanta un gatillo en PostgreSQL que
 * rechaza el `UPDATE` y el `DELETE` sobre esta tabla.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parqueadero_id
 * @property string $estacionamiento_id
 * @property string $operador_id
 * @property TipoMovimiento $tipo
 * @property \Illuminate\Support\Carbon $ocurrido_en
 * @property string $patente
 * @property string $vin
 * @property TipoVehiculo $tipo_vehiculo
 * @property string $lugar_codigo
 * @property string|null $foto_ruta
 * @property \Illuminate\Support\Carbon|null $foto_tomada_en
 */
class Movimiento extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'movimientos';

    protected $fillable = [
        'tenant_id',
        'parqueadero_id',
        'estacionamiento_id',
        'operador_id',
        'tipo',
        'ocurrido_en',
        'patente',
        'vin',
        'tipo_vehiculo',
        'lugar_codigo',
        'foto_ruta',
        'foto_tomada_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'tipo_vehiculo' => TipoVehiculo::class,
            'ocurrido_en' => 'immutable_datetime',
            'foto_tomada_en' => 'immutable_datetime',
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

    public function operador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'operador_id');
    }

    /** Lo que se puede agregar despues, porque la anotacion no cambia el hecho. */
    public function anotaciones(): HasMany
    {
        return $this->hasMany(AnotacionMovimiento::class);
    }

    public function tieneFoto(): bool
    {
        return $this->foto_ruta !== null && $this->foto_ruta !== '';
    }
}