<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edicion de la configuracion del operador.
 *
 * ESTOS VALORES CAMBIAN DINERO Y ACCESO
 * -------------------------------------
 * `minutos_cortesia` decide cuantas fracciones no se cobran, `minutos_redondeo`
 * a que multiplo se redondea, y
 * `mensualidad_vencida_bloquea_acceso` si el sistema marca un ingreso de un
 * mensualista al dia. Por eso solo administracion los cambia, y por eso los
 * rangos son cerrados: un redondeo de 0 haria que el calculo no tuviera
 * multiplicador, y una cortesia de 20 horas perdona el dia entero.
 *
 * NOTA HONESTA SOBRE EL USO REAL
 * -----------------------------
 * `minutos_cortesia` y `minutos_redondeo` se guardan y se muestran, pero el
 * cobro de hoy NO los lee: `CalculadoraTarifa` usa `tarifas.minutos_incluidos`
 * y `tarifas.minutos_por_fraccion`. Los valores por operador quedan como
 * registro de la politica del operador y como base si se decide conectarlos.
 * Ver docs/02-MODELO-DE-DATOS.md y docs/10-PRUEBAS-Y-ESTADO.md.
 */
class UpdateConfiguracionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeAdministrar() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mensualidad_vencida_bloquea_acceso' => ['nullable', 'boolean'],
            'minutos_cortesia' => ['nullable', 'integer', 'min:0', 'max:120'],
            'minutos_redondeo' => ['nullable', 'integer', 'min:1', 'max:60'],
            'zona_horaria' => ['nullable', 'string', 'max:64', 'timezone'],
            'dias_aviso_vencimiento' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'minutos_cortesia.max' => 'Dos horas de cortesia ya es generoso. Revise si el valor es correcto.',
            'minutos_redondeo.min' => 'El redondeo tiene que ser de un minuto o mas.',
            'zona_horaria.timezone' => 'Esa zona horaria no existe. Para Chile normalmente es America/Santiago.',
            'dias_aviso_vencimiento.min' => 'Avise al menos un dia antes del vencimiento.',
        ];
    }
}