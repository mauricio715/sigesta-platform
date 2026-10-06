<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formato estándar de respuesta de la API:
 *   { "status": "success" | "error", "message": "...", "data": ... }
 */
abstract class ApiController extends Controller
{
    protected function success(mixed $data = null, string $message = 'Operación exitosa.', int $status = 200): JsonResponse
    {
        if ($data instanceof JsonResource) {
            return $data
                ->additional(['status' => 'success', 'message' => $message])
                ->response()
                ->setStatusCode($status);
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ] + $extra, $status);
    }

    /** Tamaño de página acotado (evita respuestas gigantes) */
    protected function porPagina(?int $valor, int $defecto = 20): int
    {
        return max(1, min($valor ?? $defecto, 100));
    }
}
