<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (POST, campos obligatorios) y edición (PUT/PATCH, parcial) de productos terminados */
class GuardarProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('post') ? 'required' : 'sometimes';
        $producto = $this->route('producto');

        return [
            'codigo' => [$requerido, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('productos', 'codigo')->ignore($producto?->id)],
            'nombre' => [$requerido, 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'unidad_medida' => [$requerido, Rule::in(GuardarInsumoRequest::UNIDADES)],
            'dias_vida_util' => [$requerido, 'integer', 'min:0', 'max:3650'],
            'stock_minimo' => ['sometimes', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'porcentaje_alerta_preventiva' => ['sometimes', 'numeric', 'min:1', 'max:100', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'El código es obligatorio.',
            'codigo.unique' => 'Ya existe un producto con ese código.',
            'nombre.required' => 'El nombre es obligatorio.',
            'unidad_medida.in' => 'Unidad de medida no válida. Opciones: ' . implode(', ', GuardarInsumoRequest::UNIDADES) . '.',
            'dias_vida_util.required' => 'Los días de vida útil son obligatorios.',
            'dias_vida_util.integer' => 'Los días de vida útil deben ser un número entero.',
        ];
    }
}
