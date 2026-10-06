<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo_lote' => $this->codigo_lote,
            'codigo_lote_proveedor' => $this->codigo_lote_proveedor,
            'tipo_item' => $this->tipo_item,
            'insumo_id' => $this->insumo_id,
            'producto_id' => $this->producto_id,
            'fecha_fabricacion' => $this->fecha_fabricacion?->toDateString(),
            'fecha_vencimiento' => $this->fecha_vencimiento?->toDateString(),
            // Negativo si ya venció (Carbon 2 y 3 devuelven el valor con signo)
            'dias_para_vencer' => $this->fecha_vencimiento
                ? (int) round(now()->startOfDay()->diffInDays($this->fecha_vencimiento->copy()->startOfDay(), false))
                : null,
            'cantidad_inicial' => $this->cantidad_inicial,
            'cantidad_actual' => $this->cantidad_actual,
            'estado' => $this->estado,
            'insumo' => $this->whenLoaded('insumo', fn () => $this->insumo ? [
                'id' => $this->insumo->id,
                'codigo' => $this->insumo->codigo,
                'nombre' => $this->insumo->nombre,
                'unidad_medida' => $this->insumo->unidad_medida,
                'stock_actual' => $this->insumo->stock_actual,
            ] : null),
            'producto' => $this->whenLoaded('producto', fn () => $this->producto ? [
                'id' => $this->producto->id,
                'codigo' => $this->producto->codigo,
                'nombre' => $this->producto->nombre,
                'unidad_medida' => $this->producto->unidad_medida,
                'stock_actual' => $this->producto->stock_actual,
            ] : null),
            'proveedor' => $this->whenLoaded('proveedor', fn () => $this->proveedor ? [
                'id' => $this->proveedor->id,
                'codigo_proveedor' => $this->proveedor->codigo_proveedor,
                'razon_social' => $this->proveedor->razon_social,
            ] : null),
        ];
    }
}
