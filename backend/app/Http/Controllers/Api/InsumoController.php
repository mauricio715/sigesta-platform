<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GuardarInsumoRequest;
use App\Http\Resources\InsumoResource;
use App\Http\Resources\LoteResource;
use App\Models\Insumo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InsumoController extends ApiController
{
    /** GET /api/v1/insumos?buscar=&bajo_stock=1&per_page= */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'bajo_stock' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $insumos = Insumo::query()
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(
                fn ($w) => $w->where('nombre', 'like', "%{$buscar}%")->orWhere('codigo', 'like', "%{$buscar}%")
            ))
            ->when(
                filter_var($filtros['bajo_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn ($q) => $q->bajoStockMinimo()
            )
            ->orderBy('nombre')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(InsumoResource::collection($insumos), 'Listado de insumos.');
    }

    /** POST /api/v1/insumos (admin) */
    public function store(GuardarInsumoRequest $request): JsonResponse
    {
        // stock_actual no se recibe: se deriva de los lotes
        $insumo = Insumo::create($request->validated() + ['stock_actual' => 0])->refresh();

        return $this->success(new InsumoResource($insumo), 'Insumo registrado correctamente.', 201);
    }

    /** GET /api/v1/insumos/{insumo} */
    public function show(Insumo $insumo): JsonResponse
    {
        return $this->success(new InsumoResource($insumo), 'Detalle del insumo.');
    }

    /** PUT/PATCH /api/v1/insumos/{insumo} (admin) */
    public function update(GuardarInsumoRequest $request, Insumo $insumo): JsonResponse
    {
        $datos = $request->validated();

        if (isset($datos['unidad_medida'])
            && $datos['unidad_medida'] !== $insumo->unidad_medida
            && $insumo->lotes()->exists()) {
            return $this->error('No se puede cambiar la unidad de medida de un insumo con lotes registrados.', 409);
        }

        $insumo->update($datos);

        return $this->success(new InsumoResource($insumo->refresh()), 'Insumo actualizado correctamente.');
    }

    /** DELETE /api/v1/insumos/{insumo} (admin) */
    public function destroy(Insumo $insumo): JsonResponse
    {
        if ($insumo->lotes()->exists() || $insumo->recetas()->exists()) {
            return $this->error('No se puede eliminar el insumo porque tiene lotes o recetas asociadas.', 409);
        }

        $insumo->delete();

        return $this->success(null, 'Insumo eliminado correctamente.');
    }

    /** GET /api/v1/insumos/{insumo}/lotes — lotes vigentes en orden FEFO */
    public function lotes(Insumo $insumo): JsonResponse
    {
        $lotes = $insumo->lotes()
            ->disponibles()
            ->fefo()
            ->with('proveedor')
            ->get();

        return $this->success(LoteResource::collection($lotes), 'Lotes vigentes del insumo en orden FEFO.');
    }
}
