<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('post') ? 'required' : 'sometimes';
        $proveedor = $this->route('proveedor');

        return [
            'codigo_proveedor' => [$requerido, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('proveedores', 'codigo_proveedor')->ignore($proveedor?->id)],
            'razon_social' => [$requerido, 'string', 'max:200'],
            'nit' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z\-]+$/'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_proveedor.required' => 'El código de proveedor es obligatorio.',
            'codigo_proveedor.unique' => 'Ya existe un proveedor con ese código.',
            'codigo_proveedor.regex' => 'El código solo admite letras, números, guiones, puntos y guiones bajos.',
            'razon_social.required' => 'La razón social es obligatoria.',
            'nit.regex' => 'El NIT solo admite números, letras y guiones.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
        ];
    }
}
