<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GuardarProductoRequest;
use App\Http\Resources\ProductoResource;
use App\Http\Resources\RecetaResource;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductoController extends ApiController
{
    /** GET /api/v1/productos?buscar=&bajo_stock=1&per_page= */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'bajo_stock' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $productos = Producto::query()
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(
                fn ($w) => $w->where('nombre', 'like', "%{$buscar}%")->orWhere('codigo', 'like', "%{$buscar}%")
            ))
            ->when(
                filter_var($filtros['bajo_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn ($q) => $q->bajoStockMinimo()
            )
            ->orderBy('nombre')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(ProductoResource::collection($productos), 'Listado de productos.');
    }

    /** POST /api/v1/productos (admin) */
    public function store(GuardarProductoRequest $request): JsonResponse
    {
        $producto = Producto::create($request->validated() + ['stock_actual' => 0])->refresh();

        return $this->success(new ProductoResource($producto), 'Producto registrado correctamente.', 201);
    }

    /** GET /api/v1/productos/{producto} */
    public function show(Producto $producto): JsonResponse
    {
        return $this->success(new ProductoResource($producto), 'Detalle del producto.');
    }

    /** PUT/PATCH /api/v1/productos/{producto} (admin) */
    public function update(GuardarProductoRequest $request, Producto $producto): JsonResponse
    {
        $datos = $request->validated();

        if (isset($datos['unidad_medida'])
            && $datos['unidad_medida'] !== $producto->unidad_medida
            && $producto->lotes()->exists()) {
            return $this->error('No se puede cambiar la unidad de medida de un producto con lotes registrados.', 409);
        }

        $producto->update($datos);

        return $this->success(new ProductoResource($producto->refresh()), 'Producto actualizado correctamente.');
    }

    /** DELETE /api/v1/productos/{producto} (admin) */
    public function destroy(Producto $producto): JsonResponse
    {
        if ($producto->lotes()->exists() || $producto->recetas()->exists()) {
            return $this->error('No se puede eliminar el producto porque tiene lotes o recetas asociadas.', 409);
        }

        $producto->delete();

        return $this->success(null, 'Producto eliminado correctamente.');
    }

    /** GET /api/v1/productos/{producto}/recetas — recetas activas con su fórmula */
    public function recetas(Producto $producto): JsonResponse
    {
        $recetas = $producto->recetas()
            ->activas()
            ->with('insumos')
            ->latest('id')
            ->get();

        return $this->success(RecetaResource::collection($recetas), 'Recetas activas del producto.');
    }
}
