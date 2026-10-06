<?php

namespace App\Http\Resources;

use App\Support\Cantidad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DespachoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detalles = $this->relationLoaded('detalles') ? $this->detalles : null;

        return [
            'id' => $this->id,
            'codigo_despacho' => $this->codigo_despacho,
            'estado' => $this->estado,
            'fecha_despacho' => $this->fecha_despacho?->toDateTimeString(),
            'registrado_at' => $this->created_at?->toDateTimeString(),
            'observaciones' => $this->observaciones,
            'cliente' => new ClienteResource($this->whenLoaded('cliente')),
            'responsable' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'anulacion' => $this->when($this->estado === 'ANULADO', fn () => [
                'fecha' => $this->anulado_at?->toDateTimeString(),
                'motivo' => $this->motivo_anulacion,
                'responsable' => $this->relationLoaded('anuladoPor') ? $this->anuladoPor?->name : null,
            ]),
            // Un detalle por lote entregado (FEFO)
            'detalles' => $this->whenLoaded('detalles', fn () => $detalles->map(fn ($detalle) => [
                'producto' => [
                    'id' => $detalle->producto->id,
                    'codigo' => $detalle->producto->codigo,
                    'nombre' => $detalle->producto->nombre,
                    'unidad_medida' => $detalle->producto->unidad_medida,
                ],
                'lote' => [
                    'id' => $detalle->loteProducto->id,
                    'codigo_lote' => $detalle->loteProducto->codigo_lote,
                    'fecha_vencimiento' => $detalle->loteProducto->fecha_vencimiento->toDateString(),
                    'estado' => $detalle->loteProducto->estado,
                ],
                'cantidad' => $detalle->cantidad,
                'precio_unitario' => $detalle->precio_unitario,
                'subtotal' => $this->subtotal($detalle),
            ])->all()),
            'total' => $this->when($detalles !== null, function () use ($detalles) {
                $conPrecio = $detalles->filter(fn ($d) => $d->precio_unitario !== null);
                if ($conPrecio->isEmpty()) {
                    return null;
                }
                $total = $conPrecio->sum(fn ($d) => Cantidad::aMilesimas($this->subtotal($d)));

                return Cantidad::aDecimal((int) $total);
            }),
        ];
    }

    private function subtotal($detalle): ?string
    {
        if ($detalle->precio_unitario === null) {
            return null;
        }

        return Cantidad::aDecimal((int) round(
            Cantidad::aMilesimas($detalle->precio_unitario) * Cantidad::aMilesimas($detalle->cantidad) / Cantidad::ESCALA
        ));
    }
}
