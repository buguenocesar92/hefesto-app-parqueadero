<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una sesion de estacionamiento: un vehiculo adentro, con su lugar, su hora
 * de entrada y, si ya salio, su hora de salida.
 *
 * ESTA TABLA ES EL CONTRATO CON EL CLIENTE
 * -----------------------------------------
 * `ingreso_en` y `salida_en` son la unica fuente de verdad del tiempo
 * cobrado. La boleta se arma desde aca, y si el cliente pierde el ticket se
 * reconstruye desde aca. Nunca del dato que manda el cliente.
 *
 * `segundos_permanencia` se calcula al cerrar la sesion y se guarda. No es
 * una redundancia sin proposito: es el resultado que se facturo. Si la forma
 * de calcular cambia mañana, la boleta de ayer tiene que seguir cuadrando.
 *
 * `boleta_id` se llena al emitir la boleta. Es la unica llave que va en
 * circulo: no tiene restriccion de clave foranea porque `boletas` referencia
 * a esta tabla (migracion 000009), y una referencia cruzada no se puede
 * expresar con dos restricciones.
 *
 * ESTA A ABIERTA si `salida_en` es nulo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parqueadero_id
 * @property string $vehiculo_id
 * @property string $lugar_id
 * @property string $patente
 * @property string $vin
 * @property \Illuminate\Support\Carbon $ingreso_en
 * @property \Illuminate\Support\Carbon|null $salida_en
 * @property int|null $segundos_permanencia
 * @property string|null $boleta_id
 */
class Estacionamiento extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'estacionamientos';

    protected $fillable = [
        'tenant_id',
        'parqueadero_id',
        'vehiculo_id',
        'lugar_id',
        'patente',
        'vin',
        'ingreso_en',
        'salida_en',
        'segundos_permanencia',
        'boleta_id',
    ];

    protected function casts(): array
    {
        return [
            'ingreso_en' => 'immutable_datetime',
            'salida_en' => 'immutable_datetime',
            'segundos_permanencia' => 'integer',
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

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function lugar(): BelongsTo
    {
        return $this->belongsTo(Lugar::class);
    }

    public function boleta(): BelongsTo
    {
        return $this->belongsTo(Boleta::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    public function reclamos(): HasMany
    {
        return $this->hasMany(Reclamo::class);
    }

    public function estaAbierta(): bool
    {
        return $this->salida_en === null;
    }
}