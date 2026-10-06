<?php

namespace App\Http\Requests;

use App\Services\MermaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarMermaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol se controla con el middleware CheckRole
    }

    public function rules(): array
    {
        return [
            'lote_id' => ['required', 'integer', Rule::exists('lotes', 'id')],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'motivo' => ['required', Rule::in(MermaService::MOTIVOS)],
            'observacion' => ['nullable', 'required_if:motivo,OTRO', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'lote_id.required' => 'Debe indicar el lote a dar de baja.',
            'lote_id.exists' => 'El lote seleccionado no existe.',
            'cantidad.required' => 'Debe indicar la cantidad a dar de baja.',
            'cantidad.gt' => 'La cantidad a dar de baja debe ser mayor a cero.',
            'cantidad.decimal' => 'La cantidad admite como máximo 3 decimales.',
            'motivo.required' => 'Debe indicar el motivo de la baja.',
            'motivo.in' => 'Motivo no válido. Opciones: ' . implode(', ', MermaService::MOTIVOS) . '.',
            'observacion.required_if' => 'Debe detallar la observación cuando el motivo es OTRO.',
        ];
    }
}
