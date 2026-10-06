<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('post') ? 'required' : 'sometimes';
        $cliente = $this->route('cliente');

        return [
            'codigo_cliente' => [$requerido, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('clientes', 'codigo_cliente')->ignore($cliente?->id)],
            'razon_social' => [$requerido, 'string', 'max:200'],
            'nit_ci' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z\-]+$/'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_cliente.required' => 'El código de cliente es obligatorio.',
            'codigo_cliente.unique' => 'Ya existe un cliente con ese código.',
            'razon_social.required' => 'La razón social es obligatoria.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
        ];
    }
}
