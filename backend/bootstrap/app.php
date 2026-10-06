<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\RestringirTokensSoloLectura;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => CheckRole::class,
            'token.lectura' => RestringirTokensSoloLectura::class,
        ]);

        // Sin esto, una petición a /api sin cabecera "Accept: application/json"
        // intenta redirigir a la ruta 'login' (inexistente) y termina en error 500.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Toda la API responde en JSON con el formato { status, message, ... }

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Los datos enviados no son válidos.',
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'status' => 'error',
                'message' => 'No autenticado. Inicie sesión para continuar.',
            ], 401);
        });

        // 403, 404 (incluye modelos no encontrados), 405, 429...
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $mensajes = [
                403 => 'No tiene permisos para realizar esta acción.',
                404 => 'Recurso no encontrado.',
                405 => 'Método HTTP no permitido para esta ruta.',
                429 => 'Demasiadas solicitudes. Intente nuevamente en unos momentos.',
            ];

            return response()->json([
                'status' => 'error',
                'message' => $mensajes[$e->getStatusCode()] ?? ($e->getMessage() ?: 'Error en la solicitud.'),
            ], $e->getStatusCode(), $e->getHeaders());
        });
    })
    ->create();
