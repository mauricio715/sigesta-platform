<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenProduccionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo_orden' => $this->codigo_orden,
            'estado' => $this->estado,
            'cantidad_producida' => $this->cantidad_producida,
            'fecha_produccion' => $this->fecha_produccion?->toDateTimeString(),
            'producto' => new ProductoResource($this->whenLoaded('producto')),
            'receta' => $this->whenLoaded('receta', fn () => [
                'id' => $this->receta->id,
                'nombre_receta' => $this->receta->nombre_receta,
                'rendimiento_base' => $this->receta->rendimiento_base,
            ]),
            'lote_producto' => new LoteResource($this->whenLoaded('loteProducto')),
            'responsable' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            // Lotes de insumo consumidos (FEFO): base de la trazabilidad hacia atrás
            'consumos' => $this->whenLoaded('consumos', fn () => $this->consumos->map(fn ($consumo) => [
                'insumo' => [
                    'id' => $consumo->insumo->id,
                    'codigo' => $consumo->insumo->codigo,
                    'nombre' => $consumo->insumo->nombre,
                    'unidad_medida' => $consumo->insumo->unidad_medida,
                ],
                'lote' => [
                    'id' => $consumo->loteInsumo->id,
                    'codigo_lote' => $consumo->loteInsumo->codigo_lote,
                    'fecha_vencimiento' => $consumo->loteInsumo->fecha_vencimiento->toDateString(),
                ],
                'cantidad_consumida' => $consumo->cantidad_consumida,
            ])->all()),
        ];
    }
}
