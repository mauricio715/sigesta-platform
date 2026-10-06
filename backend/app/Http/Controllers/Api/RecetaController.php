<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GuardarRecetaRequest;
use App\Http\Resources\RecetaResource;
use App\Models\Receta;
use App\Services\RecetaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class RecetaController extends ApiController
{
    public function __construct(
        private readonly RecetaService $recetas,
    ) {}

    /** GET /api/v1/recetas?producto_id=&activa=1 — historial de versiones */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'producto_id' => ['nullable', 'integer'],
            'activa' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $recetas = Receta::query()
            ->with(['producto', 'insumos'])
            ->when($filtros['producto_id'] ?? null, fn ($q, $id) => $q->where('producto_id', $id))
            ->when(isset($filtros['activa']), fn ($q) => $q->where('activa', filter_var($filtros['activa'], FILTER_VALIDATE_BOOLEAN)))
            ->latest('id')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(RecetaResource::collection($recetas), 'Listado de recetas.');
    }

    /** GET /api/v1/recetas/{receta} */
    public function show(Receta $receta): JsonResponse
    {
        return $this->success(new RecetaResource($receta->load(['producto', 'insumos'])), 'Detalle de la receta.');
    }

    /** POST /api/v1/recetas (admin) — registra una nueva versión de la fórmula */
    public function store(GuardarRecetaRequest $request): JsonResponse
    {
        try {
            $receta = $this->recetas->crearVersion($request->validated());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(new RecetaResource($receta), 'Versión de receta registrada correctamente.', 201);
    }

    /** PATCH /api/v1/recetas/{receta}/estado (admin) — { "activa": true|false } */
    public function cambiarEstado(Request $request, Receta $receta): JsonResponse
    {
        $datos = $request->validate([
            'activa' => ['required', 'boolean'],
        ]);

        $receta = $this->recetas->cambiarEstado($receta, (bool) $datos['activa']);

        return $this->success(
            new RecetaResource($receta),
            $receta->activa ? 'Receta activada (las demás versiones del producto se desactivaron).' : 'Receta desactivada.',
        );
    }
}
