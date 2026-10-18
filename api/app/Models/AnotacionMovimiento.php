<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota escrita sobre un movimiento.
 *
 * Es la unica forma de "corregir" el registro sin tocarlo: el hecho queda
 * como fue y la aclaracion queda al lado, con su autor y su fecha.
 *
 * Un ejemplo real: el operario anota que la camara estaba sin contacto, o que
 * el vehiculo estaba estacionado con las luces apagadas y se confirmo que
 * no era el del cliente. Esa frase es la que sostiene el reclamo despues.
 *
 * La tabla NO tiene `created_at` ni `updated_at`: tiene `creado_en`. Por eso
 * este modelo apaga los sellos de tiempo automaticos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $movimiento_id
 * @property string $usuario_id
 * @property string $texto
 * @property \Illuminate\Support\Carbon $creado_en
 */
class AnotacionMovimiento extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'anotaciones_movimiento';

    /** La tabla no tiene `created_at` ni `updated_at`. */
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'movimiento_id',
        'usuario_id',
        'texto',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'creado_en' => 'immutable_datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}