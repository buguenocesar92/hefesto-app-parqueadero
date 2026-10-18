<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora: quien hizo que, y desde donde.
 *
 * A diferencia de las demas tablas de este sistema, esta NO tiene id UUID:
 * usa `bigIncrements` (migracion 000013) porque es un registro de solo
 * agregado que crece sin que nadie lo consulte por id, y un entero grande se
 * ordena e indexa mejor que un texto de 36 caracteres.
 *
 * Por eso este modelo NO usa el rasgo `HasUuids`. Es la excepcion
 * consciente del patron.
 *
 * Que se escriba aca y no en el movimiento es una decision de alcance: la
 * bitacora tambien recoge las acciones que NO mueven dinero ni vehiculos
 * (abrir el panel, exportar el cierre del dia, cambiar una tarifa).
 *
 * `datos` guarda el contexto en JSON. No debe llevar datos personales
 * completos: la bitacora es lo primero que se muestra a un tercero cuando
 * hay que entregar informacion. Ver docs/07-SEGURIDAD.md.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string|null $usuario_id
 * @property string $ambito
 * @property string $accion
 * @property string $recurso_tipo
 * @property string|null $recurso_id
 * @property string $descripcion
 * @property array<string, mixed>|null $datos
 * @property string|null $direccion_ip
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Bitacora extends Model
{
    use PerteneceAUnOperador;

    protected $table = 'bitacora';

    /** La tabla tiene `creado_en`, no `created_at`. */
    public $timestamps = false;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'ambito',
        'accion',
        'recurso_tipo',
        'recurso_id',
        'descripcion',
        'datos',
        'direccion_ip',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'datos' => 'array',
            'creado_en' => 'immutable_datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}