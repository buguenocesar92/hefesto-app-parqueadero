<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoVehiculo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos para marcar un ingreso.
 *
 * QUE SE EXIGE Y QUE NO
 * ---------------------
 * La `lugar_id` es obligatoria a proposito. Se podria aceptar "el sistema
 * busca el lugar libre mas cercano", pero eso hace que el operario marque
 * un ingreso sin saber donde quedo el vehiculo. Para un juego de claves,
 * el lugar se elige a mano y se ve en la misma pantalla.
 *
 * El VIN es obligatorio aunque la placa no lo sea. Es el identificador que
 * no cambia y del que depende el vinculo con la mensualidad; sin VIN, un
 * mensualista no se puede reconocer.
 */
class StoreIngresoRequest extends FormRequest
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
            'patente' => ['required', 'string', 'min:4', 'max:12', 'regex:/^[A-Za-z0-9 .\-]+$/'],
            // 17 caracteres. El alfabeto del VIN no usa las letras I, O ni Q,
            // porque se confunden con el uno y el cero.
            'vin' => ['required', 'string', 'size:17', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
            'tipo_vehiculo' => ['required', 'string', Rule::in(array_column(TipoVehiculo::cases(), 'value'))],
            'lugar_id' => ['required', 'uuid'],
            'foto_ruta' => ['nullable', 'string', 'max:300'],
            'color' => ['nullable', 'string', 'max:40'],
            'marca' => ['nullable', 'string', 'max:60'],
            'modelo' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patente.required' => 'La patente es obligatoria: sin ella no se puede ubicar el vehiculo.',
            'patente.regex' => 'La patente solo admite letras, numeros, punto y guion.',
            'vin.required' => 'El VIN es obligatorio: es como se reconoce una mensualidad.',
            'vin.size' => 'El VIN tiene exactamente 17 caracteres.',
            'vin.regex' => 'El VIN no lleva las letras I, O ni Q, y se escribe en mayusculas.',
            'tipo_vehiculo.required' => 'Indique el tipo de vehiculo: define la tarifa y si ocupa mas de un lugar.',
            'lugar_id.required' => 'Elija el lugar. El sistema no lo elige solo.',
        ];
    }
}