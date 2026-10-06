<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngresoInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol se controla con el middleware CheckRole
    }

    public function rules(): array
    {
        $vencimiento = ['required', 'date_format:Y-m-d', 'after_or_equal:today'];

        // Solo se compara contra la fabricación si fue enviada
        if ($this->filled('fecha_fabricacion')) {
            $vencimiento[] = 'after_or_equal:fecha_fabricacion';
        }

        return [
            'insumo_id' => ['required', 'integer', Rule::exists('insumos', 'id')],
            'proveedor_id' => ['required', 'integer', Rule::exists('proveedores', 'id')],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'fecha_fabricacion' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'fecha_vencimiento' => $vencimiento,
            'codigo_lote' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('lotes', 'codigo_lote')],
            'codigo_lote_proveedor' => ['nullable', 'string', 'max:50'],
            'documento_referencia' => ['nullable', 'string', 'max:50'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'insumo_id.required' => 'Debe indicar el insumo recibido.',
            'insumo_id.exists' => 'El insumo seleccionado no existe.',
            'proveedor_id.required' => 'Debe indicar el proveedor.',
            'proveedor_id.exists' => 'El proveedor seleccionado no existe.',
            'cantidad.required' => 'Debe indicar la cantidad recibida.',
            'cantidad.gt' => 'La cantidad recibida debe ser mayor a cero.',
            'cantidad.decimal' => 'La cantidad admite como máximo 3 decimales.',
            'fecha_fabricacion.date_format' => 'La fecha de fabricación debe tener el formato AAAA-MM-DD.',
            'fecha_fabricacion.before_or_equal' => 'La fecha de fabricación no puede ser futura.',
            'fecha_vencimiento.required' => 'La fecha de vencimiento es obligatoria.',
            'fecha_vencimiento.date_format' => 'La fecha de vencimiento debe tener el formato AAAA-MM-DD.',
            'fecha_vencimiento.after_or_equal' => 'No se puede ingresar un lote vencido o con vencimiento anterior a su fabricación.',
            'codigo_lote.unique' => 'Ya existe un lote con ese código.',
            'codigo_lote.regex' => 'El código de lote solo admite letras, números, guiones, puntos y guiones bajos.',
        ];
    }
}
