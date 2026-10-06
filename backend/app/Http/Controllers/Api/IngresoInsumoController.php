<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\IngresoInsumoRequest;
use App\Http\Resources\MovimientoInventarioResource;
use App\Services\IngresoInsumoService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class IngresoInsumoController extends ApiController
{
    /** POST /api/v1/insumos/ingreso — recepción de compra (admin y operador) */
    public function store(IngresoInsumoRequest $request, IngresoInsumoService $ingresos): JsonResponse
    {
        try {
            $movimiento = $ingresos->registrarEntradaInsumo($request->validated(), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new MovimientoInventarioResource($movimiento),
            "Ingreso registrado: lote {$movimiento->lote->codigo_lote}.",
            201,
        );
    }
}
