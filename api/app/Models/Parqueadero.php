<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sede fisica: un estacionamiento concreto, con su direccion y su zona
 * horaria.
 *
 * Un operador puede tener varias sedes. Todo lo que ocurre en elDia a dia
 * (tarifas, lugares, sesiones, boletas) cuelga de una sede y no del operador.
 *
 * `capacidad_total` es lo que el operador declara. NO es la cantidad de
 * lugares: esa se cuenta en `lugares`. Se guardan los dos porque no siempre
 * coinciden, y cuando no coinciden hay que poder decirlo. Ver
 * `OcupacionController::estado()`, que reporta la diferencia en vez de
 * esconderla.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombre
 * @property string|null $direccion
 * @property int $capacidad_total
 * @property string $zona_horaria
 * @property bool $activo
 */
class Parqueadero extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'parqueaderos';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'direccion',
        'capacidad_total',
        'zona_horaria',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'capacidad_total' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function lugares(): HasMany
    {
        return $this->hasMany(Lugar::class);
    }

    public function tarifas(): HasMany
    {
        return $this->hasMany(Tarifa::class);
    }

    public function mensualidades(): HasMany
    {
        return $this->hasMany(Mensualidad::class);
    }

    public function estacionamientos(): HasMany
    {
        return $this->hasMany(Estacionamiento::class);
    }

    public function boletas(): HasMany
    {
        return $this->hasMany(Boleta::class);
    }

    /** Total de lugares activos de la sede. */
    public function lugaresActivos(): int
    {
        return $this->lugares()->where('activo', true)->count();
    }
}