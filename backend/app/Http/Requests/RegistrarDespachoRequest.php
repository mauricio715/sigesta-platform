<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarDespachoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol se controla con el middleware CheckRole
    }

    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:producto_id,cantidad,precio_unitario'],
            'items.*.producto_id' => ['required', 'integer', Rule::exists('productos', 'id')],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'cliente_id.required' => 'Debe indicar el cliente.',
            'cliente_id.exists' => 'El cliente seleccionado no existe.',
            'items.required' => 'El despacho debe incluir al menos un producto.',
            'items.min' => 'El despacho debe incluir al menos un producto.',
            'items.*.array' => 'Cada ítem solo puede contener producto_id, cantidad y precio_unitario.',
            'items.*.producto_id.required' => 'Cada ítem debe indicar el producto.',
            'items.*.producto_id.exists' => 'Uno de los productos seleccionados no existe.',
            'items.*.cantidad.required' => 'Cada ítem debe indicar la cantidad.',
            'items.*.cantidad.gt' => 'La cantidad de cada ítem debe ser mayor a cero.',
            'items.*.cantidad.decimal' => 'La cantidad admite como máximo 3 decimales.',
            'items.*.precio_unitario.min' => 'El precio unitario no puede ser negativo.',
        ];
    }
}
