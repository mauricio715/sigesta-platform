<?php

namespace App\Exceptions;

use App\Models\Producto;
use App\Models\Receta;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * El producto no puede fabricarse porque no tiene una receta activa
 * o porque su receta está incompleta.
 */
class RecetaNoDisponibleException extends RuntimeException
{
    public static function sinRecetaActiva(Producto $producto): self
    {
        return new self(sprintf(
            'El producto "%s" (%s) no tiene una receta activa registrada.',
            $producto->nombre,
            $producto->codigo,
        ));
    }

    public static function recetaIncompleta(Receta $receta, string $detalle): self
    {
        return new self(sprintf(
            'La receta "%s" no puede usarse para producir: %s',
            $receta->nombre_receta,
            $detalle,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $this->getMessage(),
            'error' => 'RECETA_NO_DISPONIBLE',
        ], 422);
    }
}
