<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarProduccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol se controla con el middleware CheckRole
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', Rule::exists('productos', 'id')],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'codigo_lote' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-_.]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'producto_id.required' => 'Debe indicar el producto a fabricar.',
            'producto_id.exists' => 'El producto seleccionado no existe.',
            'cantidad.required' => 'Debe indicar la cantidad a producir.',
            'cantidad.numeric' => 'La cantidad debe ser numérica.',
            'cantidad.gt' => 'La cantidad a producir debe ser mayor a cero.',
            'cantidad.decimal' => 'La cantidad admite como máximo 3 decimales.',
            'codigo_lote.regex' => 'El código de lote solo admite letras, números, guiones, puntos y guiones bajos.',
        ];
    }
}
