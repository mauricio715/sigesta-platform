<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnularRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Solo admin: controlado por el middleware CheckRole
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Debe indicar el motivo de la anulación.',
            'motivo.min' => 'Describa el motivo con al menos 10 caracteres: queda registrado en el Kardex.',
            'motivo.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
