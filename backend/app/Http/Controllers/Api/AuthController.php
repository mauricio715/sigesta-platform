<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    /** POST /api/v1/auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            return $this->error('Credenciales incorrectas.', 401);
        }

        if (! $user->isActive()) {
            return $this->error('El usuario está inactivo. Contacte al administrador.', 403);
        }

        // Expiración explícita por token (config/sigesta.php)
        $minutos = config('sigesta.token_expiracion_minutos');
        $expiraEn = $minutos ? now()->addMinutes((int) $minutos) : null;

        $token = $user->createToken(
            $request->validated('device_name') ?? 'sigesta-web',
            [$user->role],
            $expiraEn,
        )->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiraEn?->toIso8601String(),
            'user' => new UserResource($user),
        ], 'Inicio de sesión exitoso.');
    }

    /** POST /api/v1/auth/logout — revoca solo el token usado en esta petición */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success(null, 'Sesión cerrada correctamente.');
    }

    /** GET /api/v1/auth/me */
    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()), 'Usuario autenticado.');
    }
}
