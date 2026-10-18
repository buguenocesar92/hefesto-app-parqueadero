<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoReclamo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un reclamo.
 *
 * LO QUE ES OBLIGATORIO Y POR QUE
 * -------------------------------
 *
 *  - `fecha_incidente`: es la puerta de entrada. Todo reclamo se investiga
 *    por una ventana de fechas en el registro, y sin fecha no hay nada que
 *    buscar. La hora es OPCIONAL a proposito: el cliente recuerda "el martes"
 *    mucho mejor que "el martes a las 15:20".
 *
 *  - `descripcion`: es lo que cuenta el cliente con sus palabras. No se
 *    resume ni se corrige: es la version del cliente, y el texto que la
 *    empresa responde es distinto del texto del cliente.
 *
 *  - `patente_vista`: lo que el cliente dijo, que puede no coincidir con lo
 *    que dice la base. Esa diferencia es la pista del reclamo, asi que se
 *    guarda tal cual.
 *
 * RUT y telefono del cliente son OPCIONALES y son dato personal. Se piden
 * porque hay que poder contactarlo, no porque sean obligatorios.
 */
class StoreReclamoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeOperar() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parqueadero_id' => ['required', 'uuid'],
            'estacionamiento_id' => ['nullable', 'uuid'],
            'folio' => ['nullable', 'string', 'max:24'],
            'fecha_incidente' => ['required', 'date_format:Y-m-d'],
            'hora_incidente' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'patente_vista' => ['nullable', 'string', 'max:12'],
            'cliente_nombre' => ['required', 'string', 'max:160'],
            'cliente_rut' => ['nullable', 'string', 'max:20', 'regex:/^\d{1,8}\.?\d{3}\.?\d{3}-[\dkK]$/'],
            'cliente_contacto' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['required', 'string', 'min:10', 'max:4000'],
            'foto_ruta' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_incidente.required' => 'La fecha del hecho es obligatoria: sin ella no se puede buscar en el registro.',
            'fecha_incidente.date_format' => 'La fecha se escribe como AAAA-MM-DD.',
            'descripcion.min' => 'Cuente con algo mas de detalle: que vio, que hizo y que resultado tuvo.',
            'cliente_rut.regex' => 'El RUT se escribe como 12.345.678-9.',
        ];
    }

    /** Estados desde los que un reclamo nuevo puede arrancar. */
    public static function estadosIniciales(): array
    {
        return [EstadoReclamo::Abierto->value, EstadoReclamo::EnRevision->value];
    }
}