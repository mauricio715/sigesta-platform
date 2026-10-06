<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo_alerta' => $this->tipo_alerta,
            'nivel_prioridad' => $this->nivel_prioridad,
            'mensaje' => $this->mensaje,
            'leida' => $this->leida,
            'lote' => $this->whenLoaded('lote', fn () => $this->lote ? [
                'id' => $this->lote->id,
                'codigo_lote' => $this->lote->codigo_lote,
                'estado' => $this->lote->estado,
                'fecha_vencimiento' => $this->lote->fecha_vencimiento->toDateString(),
                'cantidad_actual' => $this->lote->cantidad_actual,
            ] : null),
            // Insumo o producto al que se refiere la alerta
            'item' => $this->when(
                $this->relationLoaded('insumo') && $this->relationLoaded('producto'),
                fn () => match (true) {
                    $this->insumo !== null => ['tipo' => 'INSUMO', 'id' => $this->insumo->id, 'codigo' => $this->insumo->codigo, 'nombre' => $this->insumo->nombre],
                    $this->producto !== null => ['tipo' => 'PRODUCTO', 'id' => $this->producto->id, 'codigo' => $this->producto->codigo, 'nombre' => $this->producto->nombre],
                    default => null,
                },
            ),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
