<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoVehiculo;
use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Vehiculo conocido por el operador.
 *
 * Se identifica por VIN, no por patente: la patente se cambia, se.roba y se
 * vuelve a asignar. El VIN identifica al vehiculo de por vida. Por eso el
 * indice unico es por VIN (migracion 000007).
 *
 * La `patente` es una foto del momento y se escribe con la que tiene el
 * vehiculo hoy. La que tenia el dia del reclamo esta en `movimientos.patente`,
 * que es una copia literal y no se toca nunca.
 *
 * `tipo_vehiculo` importa por dos motivos: define que tarifa se busca y si
 * el vehiculo ocupa mas de un lugar (`TipoVehiculo::ocupaMasDeUnLugar()`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patente
 * @property string $vin
 * @property TipoVehiculo $tipo_vehiculo
 * @property string|null $color
 * @property string|null $marca
 * @property string|null $modelo
 */
class Vehiculo extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'vehiculos';

    protected $fillable = [
        'tenant_id',
        'patente',
        'vin',
        'tipo_vehiculo',
        'color',
        'marca',
        'modelo',
    ];

    protected function casts(): array
    {
        return [
            'tipo_vehiculo' => TipoVehiculo::class,
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Estacionamiento::class);
    }

    /** La mensualidad activa de este vehiculo, si tiene. */
    public function mensualidad(): HasOne
    {
        return $this->hasOne(Mensualidad::class)->where('estado', Mensualidad::ESTADO_ACTIVA);
    }

    public function descripcion(): string
    {
        return trim(sprintf(
            '%s %s%s',
            (string) $this->marca,
            (string) $this->modelo,
            $this->color === null ? '' : ' ' . $this->color
        )) ?: $this->tipo_vehiculo->etiqueta();
    }
}