<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegistrarProduccionRequest;
use App\Http\Resources\OrdenProduccionResource;
use App\Services\ProduccionService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ProduccionController extends ApiController
{
    /**
     * POST /api/v1/produccion
     *
     * StockInsuficienteException y RecetaNoDisponibleException responden
     * 422 automáticamente mediante su propio método render().
     */
    public function store(RegistrarProduccionRequest $request, ProduccionService $produccion): JsonResponse
    {
        try {
            $orden = $produccion->ejecutarOrdenProduccion(
                (int) $request->validated('producto_id'),
                (float) $request->validated('cantidad'),
                $request->user()->id,
                $request->validated('codigo_lote'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new OrdenProduccionResource($orden),
            "Orden {$orden->codigo_orden} ejecutada correctamente.",
            201,
        );
    }
}
