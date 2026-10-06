<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AnularRequest;
use App\Http\Resources\LoteResource;
use App\Models\Lote;
use App\Services\AnulacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class LoteController extends ApiController
{
    /**
     * GET /api/v1/lotes?buscar=&tipo_item=&item_id=&estado=
     * Busca por código interno o código del proveedor. Permite al frontend
     * y al asistente de IA obtener el id de un lote a partir de su código.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:50'],
            'tipo_item' => ['nullable', 'required_with:item_id', Rule::in([Lote::TIPO_INSUMO, Lote::TIPO_PRODUCTO])],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', Rule::in([
                Lote::ESTADO_ACTIVO, Lote::ESTADO_PROXIMO_A_VENCER, Lote::ESTADO_VENCIDO, Lote::ESTADO_AGOTADO, Lote::ESTADO_ANULADO,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $lotes = Lote::query()
            ->with(['insumo', 'producto', 'proveedor'])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(
                fn ($w) => $w->where('codigo_lote', 'like', "%{$buscar}%")
                    ->orWhere('codigo_lote_proveedor', 'like', "%{$buscar}%")
            ))
            ->when($filtros['tipo_item'] ?? null, fn ($q, $tipo) => $q->where('tipo_item', $tipo))
            ->when($filtros['item_id'] ?? null, fn ($q, $id) => $q->where(
                ($filtros['tipo_item'] ?? null) === Lote::TIPO_PRODUCTO ? 'producto_id' : 'insumo_id',
                $id,
            ))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->latest('id')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(LoteResource::collection($lotes), 'Listado de lotes.');
    }

    /**
     * POST /api/v1/lotes/{lote}/anular (admin) — { motivo }
     * Anula el ingreso de compra de un lote de insumo que aún no se usó.
     */
    public function anular(AnularRequest $request, Lote $lote, AnulacionService $anulaciones): JsonResponse
    {
        try {
            $lote = $anulaciones->anularIngresoCompra($lote->id, $request->user()->id, $request->validated('motivo'));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(new LoteResource($lote), "Ingreso del lote {$lote->codigo_lote} anulado.");
    }
}
