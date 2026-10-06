<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GuardarUsuarioRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administración de usuarios (solo admin). No hay eliminación física:
 * los usuarios figuran en el Kardex y las órdenes; se desactivan.
 */
class UserController extends ApiController
{
    /** GET /api/v1/usuarios?buscar=&role=&status= */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_OPERADOR])],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $usuarios = User::query()
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$buscar}%")->orWhere('email', 'like', "%{$buscar}%")
            ))
            ->when($filtros['role'] ?? null, fn ($q, $rol) => $q->where('role', $rol))
            ->when($filtros['status'] ?? null, fn ($q, $estado) => $q->where('status', $estado))
            ->orderBy('name')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(UserResource::collection($usuarios), 'Listado de usuarios.');
    }

    /** POST /api/v1/usuarios */
    public function store(GuardarUsuarioRequest $request): JsonResponse
    {
        $usuario = User::create($request->validated() + ['status' => User::STATUS_ACTIVE])->refresh();

        return $this->success(new UserResource($usuario), 'Usuario registrado correctamente.', 201);
    }

    /** GET /api/v1/usuarios/{usuario} */
    public function show(User $usuario): JsonResponse
    {
        return $this->success(new UserResource($usuario), 'Detalle del usuario.');
    }

    /**
     * PUT/PATCH /api/v1/usuarios/{usuario}
     * Cambiar el rol o desactivar al usuario revoca todos sus tokens.
     */
    public function update(GuardarUsuarioRequest $request, User $usuario): JsonResponse
    {
        $datos = $request->validated();
        unset($datos['password_confirmation']);

        $nuevoRol = $datos['role'] ?? $usuario->role;
        $nuevoEstado = $datos['status'] ?? $usuario->status;

        // Evita que el administrador se bloquee a sí mismo
        if ($request->user()->is($usuario)
            && ($nuevoRol !== User::ROLE_ADMIN || $nuevoEstado !== User::STATUS_ACTIVE)) {
            return $this->error('No puede quitarse el rol de administrador ni desactivar su propia cuenta.', 422);
        }

        $revocarTokens = $nuevoRol !== $usuario->role
            || ($nuevoEstado === User::STATUS_INACTIVE && $usuario->status === User::STATUS_ACTIVE);

        $usuario->update($datos);

        if ($revocarTokens) {
            $usuario->tokens()->delete();
        }

        return $this->success(
            new UserResource($usuario->refresh()),
            $revocarTokens
                ? 'Usuario actualizado. Sus sesiones activas fueron cerradas.'
                : 'Usuario actualizado correctamente.',
        );
    }
}
