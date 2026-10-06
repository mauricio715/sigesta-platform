<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (POST, campos obligatorios) y edición (PUT/PATCH, parcial) de insumos */
class GuardarInsumoRequest extends FormRequest
{
    public const UNIDADES = ['kg', 'gr', 'lt', 'ml', 'unidad'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('post') ? 'required' : 'sometimes';
        $insumo = $this->route('insumo');

        return [
            'codigo' => [$requerido, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('insumos', 'codigo')->ignore($insumo?->id)],
            'nombre' => [$requerido, 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'unidad_medida' => [$requerido, Rule::in(self::UNIDADES)],
            'stock_minimo' => ['sometimes', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'porcentaje_alerta_preventiva' => ['sometimes', 'numeric', 'min:1', 'max:100', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'El código es obligatorio.',
            'codigo.unique' => 'Ya existe un insumo con ese código.',
            'codigo.regex' => 'El código solo admite letras, números, guiones, puntos y guiones bajos.',
            'nombre.required' => 'El nombre es obligatorio.',
            'unidad_medida.required' => 'La unidad de medida es obligatoria.',
            'unidad_medida.in' => 'Unidad de medida no válida. Opciones: ' . implode(', ', self::UNIDADES) . '.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
            'porcentaje_alerta_preventiva.min' => 'El porcentaje de alerta debe estar entre 1 y 100.',
            'porcentaje_alerta_preventiva.max' => 'El porcentaje de alerta debe estar entre 1 y 100.',
        ];
    }
}
