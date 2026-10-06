<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AnularRequest;
use App\Http\Requests\RegistrarDespachoRequest;
use App\Http\Resources\DespachoResource;
use App\Models\Despacho;
use App\Services\AnulacionService;
use App\Services\DespachoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DespachoController extends ApiController
{
    private const RELACIONES = ['cliente', 'user', 'anuladoPor', 'detalles.producto', 'detalles.loteProducto'];

    /**
     * GET /api/v1/despachos — historial
     *   buscar (código), cliente_id, estado, fecha_inicio, fecha_fin, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $fechaFin = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('fecha_inicio')) {
            $fechaFin[] = 'after_or_equal:fecha_inicio';
        }

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:50'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', Rule::in([Despacho::ESTADO_COMPLETADO, Despacho::ESTADO_ANULADO])],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => $fechaFin,
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $despachos = Despacho::query()
            ->with(self::RELACIONES)
            ->when($filtros['buscar'] ?? null, fn ($q, $codigo) => $q->where('codigo_despacho', 'like', "%{$codigo}%"))
            ->when($filtros['cliente_id'] ?? null, fn ($q, $id) => $q->where('cliente_id', $id))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['fecha_inicio'] ?? null, fn ($q, $f) => $q->where('fecha_despacho', '>=', Carbon::parse($f)->startOfDay()))
            ->when($filtros['fecha_fin'] ?? null, fn ($q, $f) => $q->where('fecha_despacho', '<=', Carbon::parse($f)->endOfDay()))
            ->orderByDesc('fecha_despacho')
            ->orderByDesc('id')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(DespachoResource::collection($despachos), 'Historial de despachos.');
    }

    /** GET /api/v1/despachos/{despacho} */
    public function show(Despacho $despacho): JsonResponse
    {
        return $this->success(new DespachoResource($despacho->load(self::RELACIONES)), 'Detalle del despacho.');
    }

    /** POST /api/v1/despachos */
    public function store(RegistrarDespachoRequest $request, DespachoService $despachos): JsonResponse
    {
        try {
            $despacho = $despachos->registrarDespacho(
                (int) $request->validated('cliente_id'),
                $request->validated('items'),
                $request->user()->id,
                $request->validated('observaciones'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new DespachoResource($despacho),
            "Despacho {$despacho->codigo_despacho} registrado correctamente.",
            201,
        );
    }

    /** POST /api/v1/despachos/{despacho}/anular (admin) — { motivo } */
    public function anular(AnularRequest $request, Despacho $despacho, AnulacionService $anulaciones): JsonResponse
    {
        try {
            $despacho = $anulaciones->anularDespacho($despacho->id, $request->user()->id, $request->validated('motivo'));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new DespachoResource($despacho),
            "Despacho {$despacho->codigo_despacho} anulado. El producto volvió a sus lotes de origen.",
        );
    }
}
