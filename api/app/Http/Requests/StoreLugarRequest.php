<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Lugar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edicion de un lugar.
 *
 * EL CODIGO NO SE CAMBIA SOLO
 * --------------------------
 * El codigo es lo que esta pintado en el suelo y lo que el operario escribe
 * con el dedo. Un lugar cuyo codigo cambia es un lugar que deja de existir
 * para todos los que ya lo memoricen, y el registro guarda el codigo como
 * era. Por eso al editar no se toca el codigo: si esta equivocado, se
 * desactiva el lugar y se crea otro con el codigo correcto.
 *
 * LA UNICIDAD ES POR SEDE, NO GLOBAL
 * ---------------------------------
 * Dos sedes pueden tener cada una su "A-01". Se comprueba en la base con el
 * indice unico `unico_lugar_por_parqueadero` (migracion 000006); la regla de
 * Laravel es solo para dar un mensaje claro antes de llegar a la base.
 */
class StoreLugarRequest extends FormRequest
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
        $parqueaderoId = $this->route('parqueadero')?->id
            ?? $this->input('parqueadero_id');

        $esEdicion = $this->route('lugar') !== null;

        return [
            'codigo' => [
                $esEdicion ? 'prohibited' : 'required',
                'string',
                'max:24',
                'regex:/^[A-Za-z0-9 .\-]+$/',
                Rule::unique('lugares', 'codigo')
                    ->where('parqueadero_id', $parqueaderoId)
                    ->ignore($this->route('lugar')),
            ],
            'parqueadero_id' => [$esEdicion ? 'prohibited' : 'required', 'uuid'],
            'tipo' => ['required', 'string', Rule::in(Lugar::TIPOS)],
            'reservado_para' => ['nullable', 'string', 'max:160'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'El codigo es lo que se lee en el cartel del suelo. Es obligatorio.',
            'codigo.unique' => 'Ya existe un lugar con ese codigo en esta sede.',
            'codigo.prohibited' => 'El codigo de un lugar no se cambia. Desactive este lugar y cree otro con el codigo correcto.',
            'parqueadero_id.prohibited' => 'Un lugar no se cambia de sede. Cree uno en la sede correcta.',
            'tipo.in' => 'El tipo de lugar debe ser uno de los conocidos.',
        ];
    }
}