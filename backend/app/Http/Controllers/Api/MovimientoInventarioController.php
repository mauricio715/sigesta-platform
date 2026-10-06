<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MovimientoInventarioResource;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Support\Cantidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MovimientoInventarioController extends ApiController
{
    public const TIPOS = [
        MovimientoInventario::ENTRADA_COMPRA,
        MovimientoInventario::CONSUMO_PRODUCCION,
        MovimientoInventario::INGRESO_PRODUCCION,
        MovimientoInventario::SALIDA_VENTA,
        MovimientoInventario::MERMA_DESECHO,
        MovimientoInventario::BAJA_VENCIMIENTO,
        MovimientoInventario::DEVOLUCION_CLIENTE,
        MovimientoInventario::ANULACION_COMPRA,
    ];

    /**
     * GET /api/v1/movimientos — Kardex
     *   lote_id, tipo_item (INSUMO|PRODUCTO), item_id (requiere tipo_item),
     *   tipo_movimiento, fecha_inicio, fecha_fin (AAAA-MM-DD), per_page
     *
     * Con lote_id o item_id se agrega "resumen" (entradas, salidas y saldo
     * neto del período); sin ellos las unidades no serían comparables.
     */
    public function index(Request $request): JsonResponse
    {
        $fechaFin = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('fecha_inicio')) {
            $fechaFin[] = 'after_or_equal:fecha_inicio';
        }

        $filtros = $request->validate([
            'lote_id' => ['nullable', 'integer', 'min:1'],
            'tipo_item' => ['nullable', 'required_with:item_id', Rule::in([Lote::TIPO_INSUMO, Lote::TIPO_PRODUCTO])],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'tipo_movimiento' => ['nullable', Rule::in(self::TIPOS)],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => $fechaFin,
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'tipo_movimiento.in' => 'Tipo de movimiento no válido. Opciones: ' . implode(', ', self::TIPOS) . '.',
            'tipo_item.required_with' => 'Para filtrar por item_id debe indicar tipo_item (INSUMO o PRODUCTO).',
            'fecha_fin.after_or_equal' => 'La fecha fin no puede ser anterior a la fecha inicio.',
        ]);

        $query = MovimientoInventario::query()
            ->when($filtros['lote_id'] ?? null, fn ($q, $loteId) => $q->where('lote_id', $loteId))
            ->when($filtros['tipo_item'] ?? null, function ($q, $tipoItem) use ($filtros) {
                $q->whereHas('lote', function ($lote) use ($tipoItem, $filtros) {
                    $lote->where('tipo_item', $tipoItem);

                    if (! empty($filtros['item_id'])) {
                        $lote->where($tipoItem === Lote::TIPO_INSUMO ? 'insumo_id' : 'producto_id', $filtros['item_id']);
                    }
                });
            })
            ->when($filtros['tipo_movimiento'] ?? null, fn ($q, $tipo) => $q->where('tipo_movimiento', $tipo))
            ->when($filtros['fecha_inicio'] ?? null, fn ($q, $f) => $q->where('created_at', '>=', Carbon::parse($f)->startOfDay()))
            ->when($filtros['fecha_fin'] ?? null, fn ($q, $f) => $q->where('created_at', '<=', Carbon::parse($f)->endOfDay()));

        $resumen = (! empty($filtros['lote_id']) || ! empty($filtros['item_id']))
            ? $this->resumen(clone $query)
            : null;

        $movimientos = $query
            ->with(['lote.insumo', 'lote.producto', 'user'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return MovimientoInventarioResource::collection($movimientos)
            ->additional(array_filter([
                'status' => 'success',
                'message' => 'Kardex de movimientos de inventario.',
                'resumen' => $resumen,
            ], fn ($valor) => $valor !== null))
            ->response();
    }

    /** Totales del período filtrado (en la unidad del ítem/lote filtrado) */
    private function resumen($query): array
    {
        $entradas = "'" . implode("','", MovimientoInventario::TIPOS_ENTRADA) . "'";

        $totales = $query->toBase()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_movimiento IN ({$entradas}) THEN cantidad ELSE 0 END), 0) AS entradas")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_movimiento NOT IN ({$entradas}) THEN cantidad ELSE 0 END), 0) AS salidas")
            ->selectRaw('COUNT(*) AS movimientos')
            ->first();

        $entradasM = Cantidad::aMilesimas($totales->entradas);
        $salidasM = Cantidad::aMilesimas($totales->salidas);

        return [
            'movimientos' => (int) $totales->movimientos,
            'total_entradas' => Cantidad::aDecimal($entradasM),
            'total_salidas' => Cantidad::aDecimal($salidasM),
            'saldo_neto' => Cantidad::aDecimal($entradasM - $salidasM),
        ];
    }
}
