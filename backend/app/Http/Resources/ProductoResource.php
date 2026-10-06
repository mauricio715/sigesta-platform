<?php

namespace App\Http\Resources;

use App\Support\Cantidad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'unidad_medida' => $this->unidad_medida,
            'dias_vida_util' => $this->dias_vida_util,
            'stock_minimo' => $this->stock_minimo,
            'stock_actual' => $this->stock_actual,
            'bajo_stock_minimo' => Cantidad::aMilesimas($this->stock_actual) < Cantidad::aMilesimas($this->stock_minimo),
            'porcentaje_alerta_preventiva' => $this->porcentaje_alerta_preventiva,
            'recetas' => RecetaResource::collection($this->whenLoaded('recetas')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
