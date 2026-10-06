<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC: restringe la ruta a los roles indicados.
 * Uso: ->middleware('role:admin') o ->middleware('role:admin,operador')
 *
 * También bloquea a usuarios desactivados aunque conserven un token válido.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autenticado. Inicie sesión para continuar.',
            ], 401);
        }

        if (! $user->isActive()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El usuario está inactivo. Contacte al administrador.',
            ], 403);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tiene permisos para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
