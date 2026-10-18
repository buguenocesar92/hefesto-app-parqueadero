<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parametros de negocio de un operador.
 *
 * Existe UNA fila por operador: la tabla lo garantiza con el indice unico
 * sobre `tenant_id` de la migracion 2026_10_17_000003.
 *
 * Todos estos valores son decisiones del operador, no del codigo. En
 * particular `mensualidad_vencida_bloquea_acceso`: el sistema avisa, pero no
 * cierra el porton por si solo. Ver docs/06-INGRESO-VEHICULO.md.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $mensualidad_vencida_bloquea_acceso
 * @property int $minutos_cortesia
 * @property int $minutos_redondeo
 * @property string $zona_horaria
 * @property int $dias_aviso_vencimiento
 */
class Configuracion extends Model
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'configuraciones';

    protected $fillable = [
        'tenant_id',
        'mensualidad_vencida_bloquea_acceso',
        'minutos_cortesia',
        'minutos_redondeo',
        'zona_horaria',
        'dias_aviso_vencimiento',
    ];

    protected function casts(): array
    {
        return [
            'mensualidad_vencida_bloquea_acceso' => 'boolean',
            'minutos_cortesia' => 'integer',
            'minutos_redondeo' => 'integer',
            'dias_aviso_vencimiento' => 'integer',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Devuelve la configuracion del operador, creandola con los valores por
     * omision si todavia no existe.
     *
     * Es una lectura con escritura. Se evita en los caminos calientes
     * (ingreso y salida) y solo se usa en pantallas de administracion, donde
     * abrir la pantalla ya autoriza a crear el registro de configuracion.
     */
    public static function delOperador(string $tenantId): self
    {
        $configuracion = static::query()->where('tenant_id', $tenantId)->first();

        if ($configuracion !== null) {
            return $configuracion;
        }

        return static::query()->create(['tenant_id' => $tenantId]);
    }
}