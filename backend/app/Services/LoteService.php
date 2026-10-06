<?php

namespace App\Services;

use App\Exceptions\StockInsuficienteException;
use App\Models\Alerta;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\Cantidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Núcleo transaccional de lotes: consumo bajo política FEFO
 * (First Expired, First Out) para insumos y productos terminados,
 * Kardex inmutable y alertas de stock mínimo.
 */
class LoteService
{
    /**
     * Consumo de insumo para producción (CONSUMO_PRODUCCION), en orden FEFO.
     *
     * @return Collection<int, MovimientoInventario>
     *
     * @throws InvalidArgumentException|StockInsuficienteException
     */
    public function descontarStockInsumo(
        int $insumoId,
        float $cantidadRequerida,
        int $userId,
        string $motivo,
    ): Collection {
        return $this->consumirLotesFefo(
            Lote::TIPO_INSUMO,
            $insumoId,
            $cantidadRequerida,
            MovimientoInventario::CONSUMO_PRODUCCION,
            $userId,
            $motivo,
        );
    }

    /**
     * Salida de producto terminado por venta (SALIDA_VENTA), en orden FEFO.
     *
     * @return Collection<int, MovimientoInventario>
     *
     * @throws InvalidArgumentException|StockInsuficienteException
     */
    public function descontarStockProducto(
        int $productoId,
        float $cantidad,
        int $userId,
        string $motivo,
    ): Collection {
        return $this->consumirLotesFefo(
            Lote::TIPO_PRODUCTO,
            $productoId,
            $cantidad,
            MovimientoInventario::SALIDA_VENTA,
            $userId,
            $motivo,
        );
    }

    /**
     * Sincroniza stock_actual con la suma de los lotes vigentes del ítem.
     * Se recalcula desde los lotes (fuente de verdad) en lugar de restar,
     * de modo que cualquier desfase previo se corrige solo.
     */
    public function recalcularStock(Insumo|Producto $item): Insumo|Producto
    {
        $total = $this->lotesDisponiblesQuery($this->tipoDe($item), $item->id)->sum('cantidad_actual');

        $item->update([
            'stock_actual' => Cantidad::aDecimal(Cantidad::aMilesimas($total)),
        ]);

        return $item;
    }

    public function recalcularStockInsumo(Insumo $insumo): Insumo
    {
        $this->recalcularStock($insumo);

        return $insumo;
    }

    public function recalcularStockProducto(Producto $producto): Producto
    {
        $this->recalcularStock($producto);

        return $producto;
    }

    /**
     * Crea o actualiza la alerta STOCK_MINIMO del ítem si su stock quedó
     * por debajo del umbral. Si el stock se repuso, da por atendidas
     * (leídas) las alertas de stock mínimo que seguían abiertas.
     */
    public function evaluarStockMinimo(Insumo|Producto $item): ?Alerta
    {
        $stock = Cantidad::aMilesimas($item->stock_actual);
        $minimo = Cantidad::aMilesimas($item->stock_minimo);
        $columna = $item instanceof Insumo ? 'insumo_id' : 'producto_id';

        if ($minimo === 0 || $stock >= $minimo) {
            Alerta::query()
                ->where($columna, $item->id)
                ->where('tipo_alerta', Alerta::STOCK_MINIMO)
                ->where('leida', false)
                ->update(['leida' => true]);

            return null;
        }

        // ALTA si quedó en la mitad del mínimo o menos; MEDIA en otro caso
        $prioridad = $stock <= intdiv($minimo, 2)
            ? Alerta::PRIORIDAD_ALTA
            : Alerta::PRIORIDAD_MEDIA;

        return Alerta::updateOrCreate(
            [
                $columna => $item->id,
                'tipo_alerta' => Alerta::STOCK_MINIMO,
                'leida' => false,
            ],
            [
                'mensaje' => sprintf(
                    'Stock de "%s" por debajo del mínimo: %s %s disponibles (mínimo %s %s). Programar reabastecimiento.',
                    $item->nombre,
                    Cantidad::aDecimal($stock),
                    $item->unidad_medida,
                    Cantidad::aDecimal($minimo),
                    $item->unidad_medida,
                ),
                'nivel_prioridad' => $prioridad,
            ],
        );
    }

    // ---------- Internos ----------

    /**
     * Algoritmo FEFO común a insumos y productos. Operación atómica:
     * o se descuenta todo, o nada.
     *
     * @return Collection<int, MovimientoInventario> Un movimiento por lote afectado
     */
    private function consumirLotesFefo(
        string $tipoItem,
        int $itemId,
        float $cantidad,
        string $tipoMovimiento,
        int $userId,
        string $motivo,
    ): Collection {
        $requerido = Cantidad::aMilesimas($cantidad);

        if ($requerido <= 0) {
            throw new InvalidArgumentException('La cantidad a descontar debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($tipoItem, $itemId, $requerido, $tipoMovimiento, $userId, $motivo) {
            // Bloqueo pesimista: evita que dos operaciones simultáneas
            // consuman el mismo saldo (condición de carrera).
            $item = ($tipoItem === Lote::TIPO_INSUMO ? Insumo::query() : Producto::query())
                ->lockForUpdate()
                ->findOrFail($itemId);

            $lotes = $this->lotesDisponiblesQuery($tipoItem, $item->id)
                ->fefo()
                ->lockForUpdate()
                ->get();

            $disponible = $lotes->sum(fn (Lote $lote) => Cantidad::aMilesimas($lote->cantidad_actual));

            if ($disponible < $requerido) {
                throw new StockInsuficienteException(
                    tipoItem: $tipoItem,
                    itemId: $item->id,
                    nombreItem: $item->nombre,
                    cantidadRequerida: Cantidad::aDecimal($requerido),
                    cantidadDisponible: Cantidad::aDecimal($disponible),
                    unidadMedida: $item->unidad_medida,
                );
            }

            $pendiente = $requerido;
            $movimientos = collect();

            foreach ($lotes as $lote) {
                if ($pendiente === 0) {
                    break;
                }

                $saldoLote = Cantidad::aMilesimas($lote->cantidad_actual);
                $consumo = min($saldoLote, $pendiente);
                $nuevoSaldo = $saldoLote - $consumo;

                $lote->cantidad_actual = Cantidad::aDecimal($nuevoSaldo);

                if ($nuevoSaldo === 0) {
                    $lote->estado = Lote::ESTADO_AGOTADO;
                }

                $lote->save();

                $movimientos->push(MovimientoInventario::create([
                    'tipo_movimiento' => $tipoMovimiento,
                    'lote_id' => $lote->id,
                    'cantidad' => Cantidad::aDecimal($consumo),
                    'motivo_observacion' => $motivo,
                    'user_id' => $userId,
                ]));

                $pendiente -= $consumo;
            }

            $this->recalcularStock($item);
            $this->evaluarStockMinimo($item);

            return $movimientos;
        });
    }

    /** Lotes con saldo, en estado utilizable y con fecha vigente */
    private function lotesDisponiblesQuery(string $tipoItem, int $itemId): Builder
    {
        $columna = $tipoItem === Lote::TIPO_INSUMO ? 'insumo_id' : 'producto_id';

        return Lote::query()
            ->where('tipo_item', $tipoItem)
            ->where($columna, $itemId)
            ->disponibles();
    }

    private function tipoDe(Insumo|Producto $item): string
    {
        return $item instanceof Insumo ? Lote::TIPO_INSUMO : Lote::TIPO_PRODUCTO;
    }
}
