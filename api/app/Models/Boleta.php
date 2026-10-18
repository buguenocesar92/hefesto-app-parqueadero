<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Boleta de un estacionamiento pagado por operacion.
 *
 * Es un documento de dinero, asi que tiene reglas duras:
 *
 *  - El `folio` es unico por operador (indice `unico_folio_por_operador`) y
 *    lo genera `GeneradorDeBoleta`, nunca la pantalla.
 *  - `desglose` guarda el detalle del calculo tal como salio: fracciones,
 *    minutos incluidos, total. Es lo que permite reconstruir la cuenta mas
 *    tarde, aunque cambie la tarifa.
 *  - Los importes van en pesos enteros, sin decimales: `unsignedInteger`.
 *    Chilean CLP no tiene centimos.
 *  - `estado` es `emitida` o `anulada`. Anular no borra ni cambia el total:
 *    se marca con el motivo y queda el documento.
 *
 * El vinculo con `estacionamientos.boleta_id` es el mismo en los dos
 * sentidos y a proposito no tiene restricciones cruzadas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parqueadero_id
 * @property string $estacionamiento_id
 * @property string $operador_id
 * @property string $folio
 * @property \Illuminate\Support\Carbon $emitida_en
 * @property array<string, mixed> $desglose
 * @property int $subtotal
 * @property int $total
 * @property int $descuento
 * @property string $forma_pago
 * @property string $estado
 * @property string|null $anulacion_motivo
 */
class Boleta extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'boletas';

    public const ESTADO_EMITIDA = 'emitida';

    public const ESTADO_ANULADA = 'anulada';

    /** Formas de pago que el mostrador acepta. */
    public const FORMAS_DE_PAGO = ['efectivo', 'tarjeta', 'transferencia'];

    protected $fillable = [
        'tenant_id',
        'parqueadero_id',
        'estacionamiento_id',
        'operador_id',
        'folio',
        'emitida_en',
        'desglose',
        'subtotal',
        'total',
        'descuento',
        'forma_pago',
        'estado',
        'anulacion_motivo',
    ];

    protected function casts(): array
    {
        return [
            'emitida_en' => 'immutable_datetime',
            'desglose' => 'array',
            'subtotal' => 'integer',
            'total' => 'integer',
            'descuento' => 'integer',
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

    public function estaEmitida(): bool
    {
        return $this->estado === self::ESTADO_EMITIDA;
    }
}