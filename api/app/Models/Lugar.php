<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un lugar fisico de la sede: un puesto de estacionamiento.
 *
 * `codigo` es lo que el operario escribe en el cartel del suelo y lo que
 * aparece en la pantalla. La unicidad es por sede (indice unico
 * `unico_lugar_por_parqueadero` de la migracion 000006), no global: dos
 * sedes pueden tener el lugar "A-01".
 *
 * QUE ESTA OCUPADO NO ES UNA COLUMNA
 * ----------------------------------
 * `lugares` no tiene `ocupado`. Un lugar esta ocupado si existe una fila en
 * `estacionamientos` con ese `lugar_id` y `salida_en` nulo. Lo garantiza el
 * indice unico parcial `unico_lugar_sesion_abierta` (migracion 000015).
 *
 * El motivo esta escrito en `CalculadoraOcupacion` y se repite aqui porque
 * es la pregunta que hace todo el mundo: poner `ocupado` en esta tabla seria
 * el error clasico, con dos operarios leyendo `ocupado = false` al mismo
 * tiempo y el ultimo escribiendo `true`.
 *
 * `tipo` distingue los lugares que no son intercambiables: un puesto
 * reservado para carga, uno para personas con movilidad reducida, uno para
 * motos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parqueadero_id
 * @property string $codigo
 * @property string $tipo
 * @property string|null $reservado_para
 * @property bool $activo
 */
class Lugar extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'lugares';

    /** Tipos de lugar que el sistema distingue. */
    public const TIPO_VISITANTE = 'visitante';

    public const TIPO_MENSUALISTA = 'mensualista';

    public const TIPO_MOTO = 'moto';

    public const TIPO_CARGA = 'carga';

    public const TIPO_ACCESIBLE = 'accesible';

    public const TIPOS = [
        self::TIPO_VISITANTE,
        self::TIPO_MENSUALISTA,
        self::TIPO_MOTO,
        self::TIPO_CARGA,
        self::TIPO_ACCESIBLE,
    ];

    protected $fillable = [
        'tenant_id',
        'parqueadero_id',
        'codigo',
        'tipo',
        'reservado_para',
        'activo',
    ];

    protected function casts(): array
    {
        return [
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

    /** Todas las sesiones de este lugar, abiertas y cerradas. */
    public function estacionamientos(): HasMany
    {
        return $this->hasMany(Estacionamiento::class);
    }

    /** La sesion abierta en este lugar, si la hay. */
    public function sesionAbierta(): HasOne
    {
        return $this->hasOne(Estacionamiento::class)->whereNull('salida_en');
    }

    public function etiquetaDeTipo(): string
    {
        return match ($this->tipo) {
            self::TIPO_MENSUALISTA => 'Mensualista',
            self::TIPO_MOTO => 'Moto',
            self::TIPO_CARGA => 'Carga y descarga',
            self::TIPO_ACCESIBLE => 'Accesible',
            default => 'Visitante',
        };
    }
}