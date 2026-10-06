<?php

namespace App\Exceptions;

use App\Models\Lote;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Se lanza cuando los lotes vigentes de un insumo o producto terminado
 * no alcanzan para cubrir la cantidad solicitada.
 */
class StockInsuficienteException extends RuntimeException
{
    public function __construct(
        public readonly string $tipoItem,
        public readonly int $itemId,
        public readonly string $nombreItem,
        public readonly string $cantidadRequerida,
        public readonly string $cantidadDisponible,
        public readonly string $unidadMedida,
    ) {
        parent::__construct(sprintf(
            'Stock insuficiente para el %s "%s": se requieren %s %s y solo hay %s %s disponibles en lotes vigentes.',
            $tipoItem === Lote::TIPO_INSUMO ? 'insumo' : 'producto',
            $nombreItem,
            $cantidadRequerida,
            $unidadMedida,
            $cantidadDisponible,
            $unidadMedida,
        ));
    }

    /** Respuesta automática 422 cuando la excepción llega sin capturar a la API */
    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $this->getMessage(),
            'error' => 'STOCK_INSUFICIENTE',
            'data' => [
                'tipo_item' => $this->tipoItem,
                'item_id' => $this->itemId,
                'requerido' => $this->cantidadRequerida,
                'disponible' => $this->cantidadDisponible,
                'unidad_medida' => $this->unidadMedida,
            ],
        ], 422);
    }
}
