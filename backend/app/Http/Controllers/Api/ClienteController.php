<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GuardarClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClienteController extends ApiController
{
    /** GET /api/v1/clientes?buscar=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $clientes = Cliente::query()
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(
                fn ($w) => $w->where('razon_social', 'like', "%{$buscar}%")
                    ->orWhere('codigo_cliente', 'like', "%{$buscar}%")
                    ->orWhere('nit_ci', 'like', "%{$buscar}%")
            ))
            ->orderBy('razon_social')
            ->paginate($this->porPagina(isset($filtros['per_page']) ? (int) $filtros['per_page'] : null));

        return $this->success(ClienteResource::collection($clientes), 'Listado de clientes.');
    }

    /** POST /api/v1/clientes (admin) */
    public function store(GuardarClienteRequest $request): JsonResponse
    {
        $cliente = Cliente::create($request->validated());

        return $this->success(new ClienteResource($cliente), 'Cliente registrado correctamente.', 201);
    }

    /** GET /api/v1/clientes/{cliente} */
    public function show(Cliente $cliente): JsonResponse
    {
        return $this->success(new ClienteResource($cliente), 'Detalle del cliente.');
    }

    /** PUT/PATCH /api/v1/clientes/{cliente} (admin) */
    public function update(GuardarClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $cliente->update($request->validated());

        return $this->success(new ClienteResource($cliente->refresh()), 'Cliente actualizado correctamente.');
    }
}
