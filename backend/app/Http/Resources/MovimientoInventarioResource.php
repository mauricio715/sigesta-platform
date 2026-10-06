<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoInventarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo_movimiento' => $this->tipo_movimiento,
            'es_entrada' => $this->esEntrada(),
            'cantidad' => $this->cantidad,
            'categoria_merma' => $this->categoria_merma,
            'motivo_observacion' => $this->motivo_observacion,
            'fecha' => $this->created_at?->toDateTimeString(),
            'lote' => new LoteResource($this->whenLoaded('lote')),
            'responsable' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
