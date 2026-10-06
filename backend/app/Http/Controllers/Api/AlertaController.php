<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AlertaResource;
use App\Models\Alerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertaController extends ApiController
{
    /**
     * GET /api/v1/alertas
     *   estado          = pendientes (por defecto) | leidas | todas
     *   nivel_prioridad = ALTA | MEDIA | BAJA
     *   tipo_alerta     = PREVENTIVA_VENCIMIENTO | VENCIMIENTO_CRITICO | STOCK_MINIMO
     *
     * Orden: criticidad (ALTA primero) y luego las más recientes.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(['pendientes', 'leidas', 'todas'])],
            'nivel_prioridad' => ['nullable', Rule::in([Alerta::PRIORIDAD_ALTA, Alerta::PRIORIDAD_MEDIA, Alerta::PRIORIDAD_BAJA])],
            'tipo_alerta' => ['nullable', Rule::in([Alerta::PREVENTIVA_VENCIMIENTO, Alerta::VENCIMIENTO_CRITICO, Alerta::STOCK_MINIMO])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $estado = $filtros['estado'] ?? 'pendientes';

        $alertas = Alerta::query()
            ->with(['lote', 'insumo', 'producto'])
            ->when($estado === 'pendientes', fn ($q) => $q->where('leida', false))
            ->when($estado === 'leidas', fn ($q) => $q->where('leida', true))
            ->when($filtros['nivel_prioridad'] ?? null, fn ($q, $nivel) => $q->where('nivel_prioridad', $nivel))
            ->when($filtros['tipo_alerta'] ?? null, fn ($q, $tipo) => $q->where('tipo_alerta', $tipo))
            ->orderByRaw("FIELD(nivel_prioridad, 'ALTA', 'MEDIA', 'BAJA')")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(AlertaResource::collection($alertas), 'Listado de alertas.');
    }

    /** PATCH /api/v1/alertas/{alerta}/leer */
    public function marcarLeida(Alerta $alerta): JsonResponse
    {
        $alerta->update(['leida' => true]);

        return $this->success(
            new AlertaResource($alerta->load(['lote', 'insumo', 'producto'])),
            'Alerta marcada como leída.',
        );
    }
}
