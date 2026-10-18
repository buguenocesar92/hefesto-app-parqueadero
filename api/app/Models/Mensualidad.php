<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mensualidad: el derecho de un vehiculo a entrar y salir sin pagar por
 * operacion, durante un periodo pagado.
 *
 * TRES FECHAS, TRES PROBLEMAS DISTINTOS
 * -------------------------------------
 * Es la parte que mas se confunde al olhar, asi que conviene decirlo aqui:
 *
 *  - `fecha_inicio` / `fecha_termino`: el ACUERDO COMERCIAL. Si el
 *    contrato termino, no hay nada que cobrar y hay que devolver el lugar.
 *  - `fecha_ultimo_pago`: si el cliente esta al dia con la plata.
 *  - `credencial_vence_en`: hasta cuando la credencial sirve para pasar por
 *    la barrera. Si el cliente pago y quedo sin permiso, no es un problema
 *    de plata.
 *
 * Mezclarlas en una sola columna hace que un cliente que pago pero quedo sin
 * credencial se vea igual que uno que no pago, y el operador termine
 * cobrando a quien ya no tiene contrato.
 *
 * DATOS PERSONALES
 * ----------------
 * `cliente_nombre`, `cliente_rut`, `cliente_correo` y `cliente_telefono` son
 * datos personales del titular de la mensualidad. El RUT identifica a una
 * persona: es el dato mas sensible de esta tabla. La placa y el VIN, en
 * cambio, no son datos personales sensibles: identifican un vehiculo. Ver
 * docs/07-SEGURIDAD.md.
 *
 * `credencial_codigo` es un codigo corto de la credencial fisica. No es el
 * identificador de la fila: la fila se busca por `vehiculo_id` y por VIN.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parqueadero_id
 * @property string $vehiculo_id
 * @property string $cliente_nombre
 * @property string|null $cliente_rut
 * @property string|null $cliente_correo
 * @property string|null $cliente_telefono
 * @property \Illuminate\Support\Carbon $fecha_inicio
 * @property \Illuminate\Support\Carbon|null $fecha_termino
 * @property \Illuminate\Support\Carbon|null $fecha_ultimo_pago
 * @property \Illuminate\Support\Carbon $credencial_vence_en
 * @property string|null $credencial_codigo
 * @property int $monto_mensual
 * @property string $estado
 */
class Mensualidad extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'mensualidades';

    public const ESTADO_ACTIVA = 'activa';

    public const ESTADO_SUSPENDIDA = 'suspendida';

    public const ESTADO_TERMINADA = 'terminada';

    public const ESTADOS = [
        self::ESTADO_ACTIVA,
        self::ESTADO_SUSPENDIDA,
        self::ESTADO_TERMINADA,
    ];

    protected $fillable = [
        'tenant_id',
        'parqueadero_id',
        'vehiculo_id',
        'cliente_nombre',
        'cliente_rut',
        'cliente_correo',
        'cliente_telefono',
        'fecha_inicio',
        'fecha_termino',
        'fecha_ultimo_pago',
        'credencial_vence_en',
        'credencial_codigo',
        'monto_mensual',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'immutable_date',
            'fecha_termino' => 'immutable_date',
            'fecha_ultimo_pago' => 'immutable_date',
            'credencial_vence_en' => 'immutable_date',
            'monto_mensual' => 'integer',
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

    public function sesiones(): HasMany
    {
        return $this->hasMany(Estacionamiento::class);
    }

    /**
     * El contrato sigue vigente hoy.
     *
     * Solo mira el acuerdo comercial. La plata y la credencial se miran por
     * separado, a proposito.
     */
    public function contratoVigente(?\Illuminate\Support\Carbon $hoy = null): bool
    {
        if ($this->estado !== self::ESTADO_ACTIVA) {
            return false;
        }

        $hoy = $hoy ?? now();

        return $this->fecha_inicio->startOfDay() <= $hoy
            && ($this->fecha_termino === null || $this->fecha_termino->endOfDay() >= $hoy);
    }
}