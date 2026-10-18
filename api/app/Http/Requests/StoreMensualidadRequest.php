<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de una mensualidad.
 *
 * EL RUT ES DATO PERSONAL
 * -----------------------
 * `cliente_rut` identifica a una persona y es el dato mas sensible de esta
 * pantalla. Por eso se valida con el formato chileno real, para no guardar
 * basura, pero NO es obligatorio: hay clientes que estacionan con el vehiculo
 * de la empresa y no con el nombre de una persona.
 *
 * LO QUE ES OBLIGATORIO
 * ---------------------
 * `fecha_inicio`, `credencial_vence_en` y `monto_mensual`. Las tres cosas
 * que un mostrador necesita para no tener que preguntar nada: desde cuando,
 * hasta cuando y cuanto.
 */
class StoreMensualidadRequest extends FormRequest
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
            'vehiculo_id' => ['required', 'uuid'],
            'cliente_nombre' => ['required', 'string', 'max:160'],
            // RUT chileno: hasta 8 digitos, un punto como miles y un digito
            // verificador despues del guion. El digito verificador en si no
            // se calcula aca: se valida el formato, no el numero, porque un
            // RUT mal calculado viene del cliente y no del sistema.
            'cliente_rut' => ['nullable', 'string', 'max:20', 'regex:/^\d{1,8}\.?\d{3}\.?\d{3}-[\dkK]$/'],
            'cliente_correo' => ['nullable', 'email', 'max:200'],
            'cliente_telefono' => ['nullable', 'string', 'max:40'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_termino' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'credencial_vence_en' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'credencial_codigo' => ['nullable', 'string', 'max:32'],
            'monto_mensual' => ['required', 'integer', 'min:0', 'max:10000000'],
            'estado' => ['nullable', 'string', 'in:activa,suspendida,terminada'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_rut.regex' => 'El RUT se escribe como 12.345.678-9.',
            'fecha_inicio.date_format' => 'La fecha de inicio se escribe como AAAA-MM-DD.',
            'credencial_vence_en.after_or_equal' => 'La credencial no puede vencer antes de que empiece el contrato.',
            'monto_mensual.required' => 'Indique el monto mensual en pesos.',
        ];
    }
}