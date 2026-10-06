<?php

namespace App\Http\Controllers\Api;

use App\Models\Insumo;
use App\Models\Lote;
use App\Models\Producto;
use App\Support\Cantidad;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventarioController extends ApiController
{
    /** Lotes vigentes que se muestran por ítem, en orden FEFO */
    private const LOTES_POR_ITEM = 5;

    /**
     * GET /api/v1/inventario/resumen?tipo=TODOS|INSUMO|PRODUCTO&solo_bajo_stock=1
     *
     * Foto compacta del inventario: stock, mínimo y los próximos lotes a
     * consumir/despachar según FEFO. Pensado para el dashboard y como
     * contexto (RAG) del asistente de IA.
     */
    public function resumen(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::in(['TODOS', Lote::TIPO_INSUMO, Lote::TIPO_PRODUCTO])],
            'solo_bajo_stock' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
        ]);

        $tipo = $filtros['tipo'] ?? 'TODOS';
        $soloBajoStock = filter_var($filtros['solo_bajo_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $lotesVigentes = fn ($q) => $q->disponibles()->fefo();

        $insumos = $tipo === Lote::TIPO_PRODUCTO ? new Collection() : Insumo::query()
            ->with(['lotes' => $lotesVigentes])
            ->when($soloBajoStock, fn ($q) => $q->bajoStockMinimo())
            ->orderBy('nombre')
            ->get();

        $productos = $tipo === Lote::TIPO_INSUMO ? new Collection() : Producto::query()
            ->with(['lotes' => $lotesVigentes])
            ->when($soloBajoStock, fn ($q) => $q->bajoStockMinimo())
            ->orderBy('nombre')
            ->get();

        $hoy = now()->toDateString();

        return $this->success([
            'fecha_corte' => now()->toDateTimeString(),
            'insumos' => $insumos->map(fn (Insumo $i) => $this->resumenItem($i))->values()->all(),
            'productos' => $productos->map(fn (Producto $p) => $this->resumenItem($p) + [
                'dias_vida_util' => $p->dias_vida_util,
            ])->values()->all(),
            'totales' => [
                'insumos' => $insumos->count(),
                'productos' => $productos->count(),
                // Se cuentan por separado: un merge de colecciones Eloquent
                // fusionaría un insumo y un producto que comparten id
                'items_bajo_stock_minimo' => $insumos->filter(fn ($i) => $this->bajoMinimo($i))->count()
                    + $productos->filter(fn ($p) => $this->bajoMinimo($p))->count(),
                'lotes_proximos_a_vencer' => Lote::where('estado', Lote::ESTADO_PROXIMO_A_VENCER)
                    ->where('cantidad_actual', '>', 0)
                    ->count(),
                // Vencidos con saldo: incluye los que el motor nocturno aún no marcó
                'lotes_vencidos_con_saldo' => Lote::where('cantidad_actual', '>', 0)
                    ->where('estado', '!=', Lote::ESTADO_AGOTADO)
                    ->where(fn ($q) => $q->where('estado', Lote::ESTADO_VENCIDO)->orWhereDate('fecha_vencimiento', '<', $hoy))
                    ->count(),
            ],
        ], 'Resumen de inventario.');
    }

    private function resumenItem(Insumo|Producto $item): array
    {
        $hoy = now()->startOfDay();

        return [
            'id' => $item->id,
            'tipo_item' => $item instanceof Insumo ? Lote::TIPO_INSUMO : Lote::TIPO_PRODUCTO,
            'codigo' => $item->codigo,
            'nombre' => $item->nombre,
            'unidad_medida' => $item->unidad_medida,
            'stock_actual' => $item->stock_actual,
            'stock_minimo' => $item->stock_minimo,
            'bajo_stock_minimo' => $this->bajoMinimo($item),
            'lotes_vigentes' => $item->lotes->count(),
            'lotes_fefo' => $item->lotes->take(self::LOTES_POR_ITEM)->map(fn (Lote $lote) => [
                'id' => $lote->id,
                'codigo_lote' => $lote->codigo_lote,
                'fecha_vencimiento' => $lote->fecha_vencimiento->toDateString(),
                'dias_para_vencer' => (int) round($hoy->diffInDays($lote->fecha_vencimiento->copy()->startOfDay(), false)),
                'cantidad_actual' => $lote->cantidad_actual,
                'estado' => $lote->estado,
            ])->values()->all(),
        ];
    }

    private function bajoMinimo(Insumo|Producto $item): bool
    {
        return Cantidad::aMilesimas($item->stock_actual) < Cantidad::aMilesimas($item->stock_minimo);
    }
}
