<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegistrarMermaRequest;
use App\Http\Resources\MovimientoInventarioResource;
use App\Services\MermaService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class MermaController extends ApiController
{
    /** POST /api/v1/mermas — baja de un lote específico (admin y operador) */
    public function store(RegistrarMermaRequest $request, MermaService $mermas): JsonResponse
    {
        try {
            $movimiento = $mermas->registrarMerma(
                (int) $request->validated('lote_id'),
                (float) $request->validated('cantidad'),
                $request->validated('motivo'),
                $request->user()->id,
                $request->validated('observacion'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new MovimientoInventarioResource($movimiento),
            "Baja registrada en el lote {$movimiento->lote->codigo_lote}.",
            201,
        );
    }
}
