<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoVehiculo;
use App\Models\Tarifa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una tarifa.
 *
 * LAS TRES REGLAS QUE IMPORTAN
 * ---------------------------
 *
 *  1. **El tipo es uno de los dos que el calculo entiende.** Si se acepta un
 *     tipo que `CalculadoraTarifa` no conoce, la tarifa se guarda y el fallo
 *     aparece cuando el cliente esta en la barrera, pagando.
 *
 *  2. **Por tiempo, minutos por fraccion obligatorio y mayor que cero.** Sin
 *     ese numero no hay forma de calcular el cobro.
 *
 *  3. **`vigente_hasta` no puede ser anterior a `vigente_desde`.** Una tarifa
 *     que ya termino antes de empezar no es una tarifa: es un error que
 *     haria que ninguna boleta de ese periodo tenga tarifa vigente.
 *
 * La vigencia por omision es la de AHORA, no la de hoy a medianoche: un
 * operador que sube el precio a las 11 de la manana quiere que raje desde las
 * 11, no desde la medianoche que ya paso.
 */
class StoreTarifaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeAdministrar() === true
            || $this->user()?->rol->value === 'supervisor';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parqueadero_id' => ['required', 'uuid'],
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'string', Rule::in(Tarifa::TIPOS)],
            'valor' => ['required', 'integer', 'min:0', 'max:10000000'],
            'minutos_por_fraccion' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'minutos_incluidos' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'tipo_vehiculo' => ['nullable', 'string', Rule::in(array_column(TipoVehiculo::cases(), 'value'))],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.in' => 'Solo hay dos tipos de tarifa: por tiempo y precio fijo.',
            'valor.required' => 'Indique el valor en pesos.',
            'minutos_por_fraccion.min' => 'Los minutos por fraccion tienen que ser uno o mas.',
            'vigente_hasta.after_or_equal' => 'La tarifa no puede terminar antes de empezar.',
        ];
    }

    /**
     * Quejas despues de las reglas normales, para lo que no se puede decir
     * con una regla simple.
     */
    public function withValidator($validador): void
    {
        $validador->after(function ($validador): void {
            if ($this->input('tipo') === Tarifa::TIPO_POR_TIEMPO) {
                $minutos = $this->input('minutos_por_fraccion');

                if ($minutos === null || (int) $minutos < 1) {
                    $validador->errors()->add(
                        'minutos_por_fraccion',
                        'Una tarifa por tiempo necesita los minutos de cada fraccion. Sin ese numero no se puede calcular el cobro.'
                    );
                }
            }
        });
    }
}