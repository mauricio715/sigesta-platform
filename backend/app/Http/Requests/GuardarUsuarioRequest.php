<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class GuardarUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Solo admin: controlado por el middleware CheckRole
    }

    public function rules(): array
    {
        $esAlta = $this->isMethod('post');
        $requerido = $esAlta ? 'required' : 'sometimes';
        $usuario = $this->route('usuario');

        return [
            'name' => [$requerido, 'string', 'max:150'],
            'email' => [$requerido, 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'password' => [$requerido, 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'role' => [$requerido, Rule::in([User::ROLE_ADMIN, User::ROLE_OPERADOR])],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'email.unique' => 'Ya existe un usuario con ese correo electrónico.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.letters' => 'La contraseña debe contener al menos una letra.',
            'password.numbers' => 'La contraseña debe contener al menos un número.',
            'role.required' => 'El rol es obligatorio.',
            'role.in' => 'Rol no válido. Opciones: admin, operador.',
            'status.in' => 'Estado no válido. Opciones: active, inactive.',
        ];
    }
}
