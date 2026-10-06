<?php

namespace App\Services;

use App\Exceptions\StockInsuficienteException;
use App\Models\Cliente;
use App\Models\Despacho;
use App\Models\DespachoDetalle;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Despachos a clientes: salida de producto terminado por FEFO
 * con registro del lote exacto entregado (trazabilidad hasta el cliente).
 */
class DespachoService
{
    public function __construct(
        private readonly LoteService $loteService,
    ) {}

    /**
     * Registra un despacho completo de forma atómica. Si un solo producto
     * no tiene stock vigente suficiente, no se despacha nada.
     *
     * @param  array<int, array{producto_id:int, cantidad:float|int|string, precio_unitario?:float|int|string|null}>  $items
     *
     * @throws InvalidArgumentException   Ítems vacíos o inválidos
     * @throws StockInsuficienteException Algún producto no alcanza
     */
    public function registrarDespacho(
        int $clienteId,
        array $items,
        int $userId,
        ?string $observaciones = null,
    ): Despacho {
        $lineas = $this->normalizarItems($items);

        return DB::transaction(function () use ($clienteId, $lineas, $userId, $observaciones) {
            $cliente = Cliente::findOrFail($clienteId);
            $fecha = now();

            // Código temporal único; se reemplaza por uno legible basado en el id
            $despacho = Despacho::create([
                'codigo_despacho' => 'TMP-' . Str::ulid(),
                'cliente_id' => $cliente->id,
                'user_id' => $userId,
                'fecha_despacho' => $fecha,
                'observaciones' => $observaciones,
                'estado' => Despacho::ESTADO_COMPLETADO,
            ]);

            $despacho->update([
                'codigo_despacho' => sprintf('DSP-%s-%05d', $fecha->format('Ymd'), $despacho->id),
            ]);

            $motivo = sprintf('Despacho %s - %s', $despacho->codigo_despacho, $cliente->razon_social);

            foreach ($lineas as $productoId => $linea) {
                $movimientos = $this->loteService->descontarStockProducto(
                    $productoId,
                    Cantidad::aFloat($linea['cantidad']),
                    $userId,
                    $motivo,
                );

                // Un detalle por cada lote efectivamente entregado
                foreach ($movimientos as $movimiento) {
                    DespachoDetalle::create([
                        'despacho_id' => $despacho->id,
                        'producto_id' => $productoId,
                        'lote_producto_id' => $movimiento->lote_id,
                        'cantidad' => $movimiento->cantidad,
                        'precio_unitario' => $linea['precio_unitario'],
                    ]);
                }
            }

            return $despacho->load([
                'cliente',
                'user',
                'detalles.producto',
                'detalles.loteProducto',
            ]);
        });
    }

    /**
     * Valida los ítems y consolida productos repetidos.
     * Se ordenan por producto_id para que despachos simultáneos bloqueen
     * las filas siempre en el mismo orden (previene deadlocks).
     *
     * @return array<int, array{cantidad:int, precio_unitario:?string}>
     */
    private function normalizarItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('El despacho debe incluir al menos un producto.');
        }

        $lineas = [];

        foreach (array_values($items) as $i => $item) {
            $numero = $i + 1;
            $productoId = $item['producto_id'] ?? null;

            if (! is_numeric($productoId) || (int) $productoId <= 0 || (int) $productoId != $productoId) {
                throw new InvalidArgumentException("El ítem #{$numero} no tiene un producto_id válido.");
            }

            $productoId = (int) $productoId;
            $cantidad = Cantidad::aMilesimas($item['cantidad'] ?? 0);

            if ($cantidad <= 0) {
                throw new InvalidArgumentException("El ítem #{$numero} debe tener una cantidad mayor a cero.");
            }

            $precio = null;
            if (isset($item['precio_unitario'])) {
                $precioMilesimas = Cantidad::aMilesimas($item['precio_unitario']);

                if ($precioMilesimas < 0) {
                    throw new InvalidArgumentException("El ítem #{$numero} tiene un precio unitario negativo.");
                }

                $precio = Cantidad::aDecimal($precioMilesimas);
            }

            if (isset($lineas[$productoId])) {
                if ($lineas[$productoId]['precio_unitario'] !== $precio) {
                    throw new InvalidArgumentException("El producto {$productoId} aparece varias veces con precios distintos.");
                }

                $lineas[$productoId]['cantidad'] += $cantidad;
            } else {
                $lineas[$productoId] = ['cantidad' => $cantidad, 'precio_unitario' => $precio];
            }
        }

        ksort($lineas);

        return $lineas;
    }
}
