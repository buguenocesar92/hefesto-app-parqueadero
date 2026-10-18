<?php

declare(strict_types=1);

namespace App\Services\Auditoria;

use App\Models\Bitacora;
use App\Models\Usuario;
use App\Services\Estacionamiento\RelojDelServidor;
use Illuminate\Support\Carbon;

/**
 * Escribe en la bitacora.
 *
 * UNA CLASE, UN LUGAR
 * -------------------
 * La bitacora se escribe desde el middleware y desde algunos controladores.
 * Que el INSERT este en un solo metodo es lo que hace que la columna
 * `creado_en` (que no tiene valor por omision en la base, ver migracion
 * 000013) se llene siempre igual y que ninguna pantalla se escriba a mano.
 *
 * NUNCA DATOS PERSONALES COMPLETOS
 * --------------------------------
 * `datos` guarda contexto de la accion: identificadores, numeros, nombres de
 * recurso. No se guardan ni RUT, ni correo, ni telefono, ni nombre del
 * cliente. La bitacora es lo primero que se entrega a un tercero cuando hay
 * que transparentar informacion, y lo que se entrega tiene que poder leerse.
 */
class RegistradorDeBitacora
{
    /**
     * @param  array<string, mixed>|null  $datos
     */
    public function registrar(
        string $tenantId,
        ?Usuario $usuario,
        string $ambito,
        string $accion,
        string $recursoTipo,
        ?string $recursoId,
        string $descripcion,
        ?array $datos = null,
        ?string $direccionIp = null,
    ): Bitacora {
        return Bitacora::query()->create([
            'tenant_id' => $tenantId,
            'usuario_id' => $usuario?->id,
            'ambito' => $ambito,
            'accion' => $accion,
            'recurso_tipo' => $recursoTipo,
            'recurso_id' => $recursoId,
            'descripcion' => $descripcion,
            'datos' => $datos,
            'direccion_ip' => $direccionIp,
            'creado_en' => RelojDelServidor::ahora()->toDateTime(),
        ]);
    }

    /** Marca de tiempo en el mismo reloj que usan ingresos y salidas. */
    public function ahora(): Carbon
    {
        return RelojDelServidor::ahora();
    }
}