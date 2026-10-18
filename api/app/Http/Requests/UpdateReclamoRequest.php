<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoReclamo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado o resolucion de un reclamo.
 *
 * ESTADOS DE UN RECLAMO
 * ---------------------
 * `abierto` -> `en_revision` -> `resuelto` o `rechazado`.
 *
 * Cerrar a un estado que ya no se puede volver a abrir exige `resolucion`
 * escrita, y en el caso de `rechazado` con un motivo. Un reclamo que se
 * cierra sin explicacion deja al cliente sin respuesta y a la empresa sin
 * respaldo.
 *
 * El cambio se valida tambien en `ReclamoController::cambiarEstado()`, con
 * `EstadoReclamo::puedeIrDesde()`. Aca se comprueba que el texto venga; alla
 * que el salto sea legal. Las dos cosas hacen falta.
 */
class UpdateReclamoRequest extends FormRequest
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
            'estado' => ['required', 'string', Rule::in(array_column(EstadoReclamo::cases(), 'value'))],
            'resolucion' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'Indique el estado nuevo del reclamo.',
            'estado.in' => 'Un reclamo solo puede estar abierto, en revision, resuelto o rechazado.',
        ];
    }

    /**
     * Quejas despues de las reglas normales: cerrar exige escribir.
     */
    public function withValidator($validador): void
    {
        $validador->after(function ($validador): void {
            $estado = $this->input('estado');

            $cierra = in_array($estado, [
                EstadoReclamo::Resuelto->value,
                EstadoReclamo::Rechazado->value,
            ], true);

            if ($cierra && trim((string) $this->input('resolucion')) === '') {
                $validador->errors()->add(
                    'resolucion',
                    'Un reclamo no se cierra sin escribir que se respondio. Si no hay respuesta todavia, dejelo en revision.'
                );
            }
        });
    }
}