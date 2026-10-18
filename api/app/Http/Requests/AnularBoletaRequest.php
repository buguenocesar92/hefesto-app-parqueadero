<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anulacion de una boleta.
 *
 * EL MOTIVO ES OBLIGATORIO, Y POR DOS MOTIVOS
 * -------------------------------------------
 *  1. La boleta anulada no se borra: queda con su total y con el motivo. Sin
 *     el motivo escrito, "anulada" no explica nada a nadie.
 *  2. Una anulacion es la unica forma de que el dinero cobrado por un
 *     operador cambie de verdad. Sin texto, no hay forma de saber si fue un
 *     error de tipeo, una falla de la maquina o una desviation.
 *
 * El texto se guarda en `boletas.anulacion_motivo`, que admite 300
 * caracteres: es suficiente para una explicacion y evita que el motivo crezca
 * hasta ser una novela.
 */
class AnularBoletaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La politica vuelve a comprobarlo contra la boleta concreta; esta es
        // solo la primera barrera, y es la rapida.
        return $this->user()?->rol->puedeAdministrar() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:10', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Una boleta anulada tiene que decir por que se anulo.',
            'motivo.min' => 'Explique en una linea que paso.',
        ];
    }
}