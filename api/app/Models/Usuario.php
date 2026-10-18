<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolUsuario;
use App\Models\Concerns\PerteneceAUnOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Persona que opera el parqueadero.
 *
 * La identidad la resuelve Authentik: esta tabla guarda el `authentik_subject`
 * con el que se vincula la cuenta externa y el `rol` con el que se autoriza
 * cada accion. El sistema de parqueadero NO guarda contrasenas.
 *
 * `ultimo_ingreso` se actualiza en el callback de acceso, no por el
 * middleware: asi la marca es "la ultima vez que entro por el proveedor de
 * identidad" y no "la ultima vez que hubo una peticion".
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $authentik_subject
 * @property string $nombre
 * @property string $correo
 * @property RolUsuario $rol
 * @property bool $activo
 * @property \Illuminate\Support\Carbon|null $ultimo_ingreso
 */
class Usuario extends Authenticatable
{
    use HasUuids;
    use PerteneceAUnOperador;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'authentik_subject',
        'nombre',
        'correo',
        'rol',
        'activo',
        'ultimo_ingreso',
    ];

    protected $hidden = [
        'authentik_subject',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolUsuario::class,
            'activo' => 'boolean',
            'ultimo_ingreso' => 'immutable_datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Los movimientos que esta persona registro. */
    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class, 'operador_id');
    }

    /** Accesos que el rol de esta persona permite operar. */
    public function puedeOperar(): bool
    {
        return $this->rol->puedeOperar();
    }
}