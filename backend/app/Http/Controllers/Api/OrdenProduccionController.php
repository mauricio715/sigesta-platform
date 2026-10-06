<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\OrdenProduccionResource;
use App\Models\OrdenProduccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OrdenProduccionController extends ApiController
{
    private const RELACIONES = ['producto', 'receta', 'loteProducto', 'user', 'consumos.insumo', 'consumos.loteInsumo'];

    /**
     * GET /api/v1/ordenes-produccion — historial
     *   buscar (código de orden), producto_id, fecha_inicio, fecha_fin, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $fechaFin = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('fecha_inicio')) {
            $fechaFin[] = 'after_or_equal:fecha_inicio';
        }

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:50'],
            'producto_id' => ['nullable', 'integer', 'min:1'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => $fechaFin,
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $ordenes = OrdenProduccion::query()
            ->with(self::RELACIONES)
            ->when($filtros['buscar'] ?? null, fn ($q, $codigo) => $q->where('codigo_orden', 'like', "%{$codigo}%"))
            ->when($filtros['producto_id'] ?? null, fn ($q, $id) => $q->where('producto_id', $id))
            ->when($filtros['fecha_inicio'] ?? null, fn ($q, $f) => $q->where('fecha_produccion', '>=', Carbon::parse($f)->startOfDay()))
            ->when($filtros['fecha_fin'] ?? null, fn ($q, $f) => $q->where('fecha_produccion', '<=', Carbon::parse($f)->endOfDay()))
            ->orderByDesc('fecha_produccion')
            ->orderByDesc('id')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(OrdenProduccionResource::collection($ordenes), 'Historial de órdenes de producción.');
    }

    /** GET /api/v1/ordenes-produccion/{orden} */
    public function show(OrdenProduccion $orden): JsonResponse
    {
        return $this->success(new OrdenProduccionResource($orden->load(self::RELACIONES)), 'Detalle de la orden de producción.');
    }
}
