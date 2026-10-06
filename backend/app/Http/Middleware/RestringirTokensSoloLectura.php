<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un token con la habilidad 'solo-lectura' (p. ej. el del servicio de IA)
 * solo puede ejecutar métodos seguros (GET/HEAD/OPTIONS). Cualquier intento
 * de escritura se rechaza con 403, aunque el rol del usuario lo permitiera.
 */
class RestringirTokensSoloLectura
{
    public const HABILIDAD = 'solo-lectura';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Solo lectura = tiene la habilidad explícita y NO tiene comodín '*'.
        // (Un token '*' o una sesión SPA responden can() = true a todo.)
        $esSoloLectura = $token !== null
            && method_exists($token, 'can')
            && $token->can(self::HABILIDAD)
            && ! $token->can('*');

        if ($esSoloLectura && ! $request->isMethodSafe()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este token es de solo lectura: no puede registrar ni modificar información.',
            ], 403);
        }

        return $next($request);
    }
}
