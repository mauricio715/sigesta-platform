<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarRecetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', Rule::exists('productos', 'id')],
            'nombre_receta' => ['required', 'string', 'max:150'],
            'rendimiento_base' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'activa' => ['sometimes', 'boolean'],
            'insumos' => ['required', 'array', 'min:1', 'max:50'],
            'insumos.*' => ['required', 'array:insumo_id,cantidad_requerida'],
            'insumos.*.insumo_id' => ['required', 'integer', 'distinct', Rule::exists('insumos', 'id')],
            'insumos.*.cantidad_requerida' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'producto_id.required' => 'Debe indicar el producto de la receta.',
            'producto_id.exists' => 'El producto seleccionado no existe.',
            'nombre_receta.required' => 'El nombre de la receta es obligatorio.',
            'rendimiento_base.required' => 'El rendimiento base es obligatorio.',
            'rendimiento_base.gt' => 'El rendimiento base debe ser mayor a cero.',
            'insumos.required' => 'La receta debe incluir al menos un insumo.',
            'insumos.min' => 'La receta debe incluir al menos un insumo.',
            'insumos.*.array' => 'Cada insumo solo puede contener insumo_id y cantidad_requerida.',
            'insumos.*.insumo_id.distinct' => 'Un insumo no puede repetirse dentro de la misma receta.',
            'insumos.*.insumo_id.exists' => 'Uno de los insumos seleccionados no existe.',
            'insumos.*.cantidad_requerida.gt' => 'Las cantidades de la receta deben ser mayores a cero.',
            'insumos.*.cantidad_requerida.decimal' => 'Las cantidades admiten como máximo 3 decimales.',
        ];
    }
}
