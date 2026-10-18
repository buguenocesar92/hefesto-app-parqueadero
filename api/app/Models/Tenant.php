<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa usuaria: una persona natural o juridica que opera uno o mas
 * estacionamientos con este sistema.
 *
 * Es la UNICA tabla sin `tenant_id` y sin Row Level Security, a proposito:
 * se consulta durante el acceso, cuando todavia no hay sesion y por lo tanto
 * no hay `app.tenant_id` que poner. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 *
 * No usar el trait `PerteneceAUnOperador` aqui: la tabla no tiene `tenant_id`.
 *
 * @property string $id
 * @property string $slug
 * @property string $nombre
 * @property string|null $razon_social
 * @property string|null $rut
 * @property string|null $correo_contacto
 * @property string|null $telefono_contacto
 * @property bool $activo
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'rut',
        'correo_contacto',
        'telefono_contacto',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function parqueaderos(): HasMany
    {
        return $this->hasMany(Parqueadero::class);
    }

    public function configuracion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Configuracion::class);
    }
}