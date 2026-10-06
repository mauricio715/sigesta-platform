<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GuardarProveedorRequest;
use App\Http\Resources\ProveedorResource;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProveedorController extends ApiController
{
    /** GET /api/v1/proveedores?buscar=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $proveedores = Proveedor::query()
            ->withCount('lotes')
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(
                fn ($w) => $w->where('razon_social', 'like', "%{$buscar}%")
                    ->orWhere('codigo_proveedor', 'like', "%{$buscar}%")
                    ->orWhere('nit', 'like', "%{$buscar}%")
            ))
            ->orderBy('razon_social')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(ProveedorResource::collection($proveedores), 'Listado de proveedores.');
    }

    /** POST /api/v1/proveedores (admin) */
    public function store(GuardarProveedorRequest $request): JsonResponse
    {
        $proveedor = Proveedor::create($request->validated());

        return $this->success(new ProveedorResource($proveedor), 'Proveedor registrado correctamente.', 201);
    }

    /** GET /api/v1/proveedores/{proveedor} */
    public function show(Proveedor $proveedor): JsonResponse
    {
        return $this->success(new ProveedorResource($proveedor->loadCount('lotes')), 'Detalle del proveedor.');
    }

    /** PUT/PATCH /api/v1/proveedores/{proveedor} (admin) */
    public function update(GuardarProveedorRequest $request, Proveedor $proveedor): JsonResponse
    {
        $proveedor->update($request->validated());

        return $this->success(new ProveedorResource($proveedor->refresh()), 'Proveedor actualizado correctamente.');
    }

    /** DELETE /api/v1/proveedores/{proveedor} (admin) */
    public function destroy(Proveedor $proveedor): JsonResponse
    {
        if ($proveedor->lotes()->exists()) {
            return $this->error(
                'No se puede eliminar el proveedor porque tiene lotes asociados (se perdería la trazabilidad de origen).',
                409,
            );
        }

        $proveedor->delete();

        return $this->success(null, 'Proveedor eliminado correctamente.');
    }
}
