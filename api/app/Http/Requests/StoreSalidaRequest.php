<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Boleta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos para marcar una salida.
 *
 * `estacionamiento_id` es el camino normal. Los otros dos son el camino de
 * escritorio, que existe porque el ticket se pierde: el cliente no tiene el
 * papel, pero el operador tiene la patente y a veces una hora aproximada.
 *
 * En los tres casos el tiempo que se cobra sale de `ingreso_en` y
 * `salida_en`, que estan en la base. Nunca de lo que diga el cliente.
 *
 * `salida_en` se acepta solo para el caso en que el registro quedo con la
 * hora equivocada. Por eso va con `after:ingreso_en` cuando se manda el id
 * de la sesion: una salida anterior al ingreso es un cobro negativo.
 */
class StoreSalidaRequest extends FormRequest
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
            // Alguno de los tres: o el id de la sesion, o patente, o VIN.
            'estacionamiento_id' => ['required_without_all:patente,vin', 'nullable', 'uuid'],
            'patente' => ['required_without:estacionamiento_id', 'nullable', 'string', 'max:12'],
            'vin' => ['required_without_all:estacionamiento_id,patente', 'nullable', 'string', 'size:17'],

            'forma_pago' => ['required', 'string', Rule::in(Boleta::FORMAS_DE_PAGO)],
            'descuento' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'foto_ruta' => ['nullable', 'string', 'max:300'],
            'salida_en' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estacionamiento_id.required_without_all' => 'Indique la sesion, o la patente, o el VIN: sin uno de los tres no se encuentra el vehiculo.',
            'forma_pago.required' => 'Indique como pago el cliente.',
            'descuento.min' => 'El descuento no puede ser negativo.',
        ];
    }
}