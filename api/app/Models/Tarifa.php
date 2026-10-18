<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa de una sede, con su vigencia.
 *
 * `tipo` decide COMO se cobra. Hay SOLO DOS, y son las unicas que
 * `CalculadoraTarifa::calcular()` entiende: cualquier otro valor hace fallar
 * el cobro al momento de salir el vehiculo, que es el peor momento posible.
 *
 *  - `por_tiempo`: se cobra `valor` cada `minutos_por_fraccion` minutos. Es
 *    la tarifa clasica de un estacionamiento: fracciones de 20 o 30 minutos.
 *    `minutos_por_fraccion` es obligatorio y tiene que ser mayor que cero.
 *  - `por_evento`: `valor` es un precio fijo. El tiempo no cambia el monto.
 *    Se usa para permisos de carga y descarga, o para un lugar cuyo precio no
 *    depende de las horas.
 *
 * `minutos_incluidos` son los minutos que NO se cobran, pactados con el
 * cliente. Se descuentan de la permanencia antes de contar fracciones. OJO:
 * este valor es POR TARIFA, y es el que usa el cobro. Existe ademas una
 * columna `configuraciones.minutos_cortesia`, que es un valor por operador y
 * que hoy NO participa del calculo. Ver la nota de docs/02-MODELO-DE-DATOS.md.
 *
 * `vigente_desde` y `vigente_hasta` permiten dejar el precio historico sin
 * borrarlo: una boleta de marzo se calcula con el precio de marzo, no con el
 * de hoy. `RegistradorSalida` busca la tarifa vigente por la FECHA DEL
 * INGRESO, no por la de hoy. Es la razon de que las tarifas no se editen una
 * vez usadas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parqueadero_id
 * @property string $nombre
 * @property string $tipo
 * @property int $valor
 * @property int|null $minutos_por_fraccion
 * @property int $minutos_incluidos
 * @property string|null $tipo_vehiculo
 * @property \Illuminate\Support\Carbon $vigente_desde
 * @property \Illuminate\Support\Carbon|null $vigente_hasta
 * @property bool $activo
 */
class Tarifa extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'tarifas';

    public const TIPO_POR_TIEMPO = 'por_tiempo';

    public const TIPO_POR_EVENTO = 'por_evento';

    /**
     * Los unicos dos tipos que el calculo acepta.
     *
     * No se agrega un valor aqui sin cambiar tambien
     * `CalculadoraTarifa::calcular()`. Ver la nota del encabezado.
     */
    public const TIPOS = [
        self::TIPO_POR_TIEMPO,
        self::TIPO_POR_EVENTO,
    ];

    protected $fillable = [
        'tenant_id',
        'parqueadero_id',
        'nombre',
        'tipo',
        'valor',
        'minutos_por_fraccion',
        'minutos_incluidos',
        'tipo_vehiculo',
        'vigente_desde',
        'vigente_hasta',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'integer',
            'minutos_por_fraccion' => 'integer',
            'minutos_incluidos' => 'integer',
            'vigente_desde' => 'immutable_datetime',
            'vigente_hasta' => 'immutable_datetime',
            'activo' => 'boolean',
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

    /**
     * Una tarifa por tiempo sin minutos por fraccion no es calculable.
     *
     * El calculo la rechaza con una excepcion, y solo al momento de cobrar.
     * Esta comprobacion existe para que la pantalla de alta la avise al
     * guardar, y no cuando el cliente ya esta en la barrera.
     */
    public function estaCompleta(): bool
    {
        if ($this->tipo !== self::TIPO_POR_TIEMPO) {
            return true;
        }

        return $this->minutos_por_fraccion !== null && $this->minutos_por_fraccion > 0;
    }

    public function etiqueta(): string
    {
        return match ($this->tipo) {
            self::TIPO_POR_TIEMPO => 'Por tiempo',
            self::TIPO_POR_EVENTO => 'Precio fijo',
            default => $this->tipo,
        };
    }
}