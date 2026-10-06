<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecetaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'producto_id' => $this->producto_id,
            'producto' => $this->whenLoaded('producto', fn () => [
                'id' => $this->producto->id,
                'codigo' => $this->producto->codigo,
                'nombre' => $this->producto->nombre,
                'unidad_medida' => $this->producto->unidad_medida,
            ]),
            'nombre_receta' => $this->nombre_receta,
            'rendimiento_base' => $this->rendimiento_base,
            'activa' => $this->activa,
            'observaciones' => $this->observaciones,
            'formula' => $this->whenLoaded('insumos', fn () => $this->insumos->map(fn ($insumo) => [
                'insumo_id' => $insumo->id,
                'codigo' => $insumo->codigo,
                'nombre' => $insumo->nombre,
                'unidad_medida' => $insumo->unidad_medida,
                'cantidad_requerida' => $insumo->pivot->cantidad_requerida,
            ])->all()),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
