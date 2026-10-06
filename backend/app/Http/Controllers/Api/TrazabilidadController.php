<?php

namespace App\Http\Controllers\Api;

use App\Services\TrazabilidadService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class TrazabilidadController extends ApiController
{
    public function __construct(
        private readonly TrazabilidadService $trazabilidad,
    ) {}

    /** GET /api/v1/trazabilidad/insumo/{loteId} — hacia adelante, hasta el cliente */
    public function insumo(int $loteId): JsonResponse
    {
        try {
            $reporte = $this->trazabilidad->rastrearInsumoHaciaAdelante($loteId);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($reporte, 'Trazabilidad hacia adelante del lote de insumo.');
    }

    /** GET /api/v1/trazabilidad/producto/{loteId} — hacia atrás, hasta el proveedor */
    public function producto(int $loteId): JsonResponse
    {
        try {
            $reporte = $this->trazabilidad->rastrearProductoHaciaAtras($loteId);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($reporte, 'Trazabilidad hacia atrás del lote de producto.');
    }
}
