<?php

namespace App\Services;

use App\Exceptions\RecetaNoDisponibleException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\OrdenProduccion;
use App\Models\ProduccionConsumo;
use App\Models\Producto;
use App\Models\Receta;
use App\Support\Cantidad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Órdenes de producción: descuento de insumos por receta (BOM) bajo FEFO,
 * creación del lote de producto terminado y registro de trazabilidad.
 */
class ProduccionService
{
    public function __construct(
        private readonly LoteService $loteService,
    ) {}

    /**
     * Ejecuta una orden de producción completa de forma atómica.
     * Si cualquier insumo no alcanza, se revierte todo: orden, consumos,
     * movimientos de Kardex, lote de producto y alertas.
     *
     * @throws InvalidArgumentException     Cantidad no positiva o código de lote inválido/duplicado
     * @throws RecetaNoDisponibleException  Sin receta activa o receta incompleta
     * @throws StockInsuficienteException   Algún insumo no tiene stock vigente suficiente
     */
    public function ejecutarOrdenProduccion(
        int $productoId,
        float $cantidadAProducir,
        int $userId,
        ?string $codigoLotePersonalizado = null,
    ): OrdenProduccion {
        $cantidad = Cantidad::aMilesimas($cantidadAProducir);

        if ($cantidad <= 0) {
            throw new InvalidArgumentException('La cantidad a producir debe ser mayor a cero.');
        }

        $codigoLote = $this->normalizarCodigoLote($codigoLotePersonalizado);

        return DB::transaction(function () use ($productoId, $cantidad, $userId, $codigoLote) {
            $producto = Producto::query()->lockForUpdate()->findOrFail($productoId);
            $receta = $this->obtenerRecetaActiva($producto);

            if ($codigoLote !== null && Lote::where('codigo_lote', $codigoLote)->exists()) {
                throw new InvalidArgumentException("El código de lote \"{$codigoLote}\" ya está registrado.");
            }

            $fecha = now();

            // 1. La orden se crea primero (PENDIENTE) porque su código se usa
            //    como motivo en el Kardex. Si algo falla, el rollback la elimina.
            $orden = $this->crearOrdenPendiente($producto, $receta, $cantidad, $userId, $fecha);
            $motivo = sprintf('Orden %s - %s', $orden->codigo_orden, $producto->nombre);

            // 2. Consumo de cada insumo del BOM bajo FEFO + registro de trazabilidad
            foreach ($receta->insumos as $insumo) {
                $requerido = $this->escalarCantidad(
                    $insumo->pivot->cantidad_requerida,
                    $cantidad,
                    $receta->rendimiento_base,
                );

                $movimientos = $this->loteService->descontarStockInsumo(
                    $insumo->id,
                    Cantidad::aFloat($requerido),
                    $userId,
                    $motivo,
                );

                foreach ($movimientos as $movimiento) {
                    ProduccionConsumo::create([
                        'orden_produccion_id' => $orden->id,
                        'lote_insumo_id' => $movimiento->lote_id,
                        'insumo_id' => $insumo->id,
                        'cantidad_consumida' => $movimiento->cantidad,
                    ]);
                }
            }

            // 3. Lote de producto terminado + su ingreso en el Kardex
            $loteProducto = Lote::create([
                'codigo_lote' => $codigoLote ?? sprintf('%s-%s-%05d', $producto->codigo, $fecha->format('Ymd'), $orden->id),
                'tipo_item' => Lote::TIPO_PRODUCTO,
                'producto_id' => $producto->id,
                'fecha_fabricacion' => $fecha->toDateString(),
                'fecha_vencimiento' => $fecha->copy()->addDays($producto->dias_vida_util)->toDateString(),
                'cantidad_inicial' => Cantidad::aDecimal($cantidad),
                'cantidad_actual' => Cantidad::aDecimal($cantidad),
                'estado' => Lote::ESTADO_ACTIVO,
            ]);

            MovimientoInventario::create([
                'tipo_movimiento' => MovimientoInventario::INGRESO_PRODUCCION,
                'lote_id' => $loteProducto->id,
                'cantidad' => Cantidad::aDecimal($cantidad),
                'motivo_observacion' => $motivo,
                'user_id' => $userId,
            ]);

            // 4. Cierre de la orden y actualización del stock del producto
            $orden->update([
                'lote_producto_id' => $loteProducto->id,
                'estado' => OrdenProduccion::ESTADO_COMPLETADA,
            ]);

            $this->loteService->recalcularStockProducto($producto);

            return $orden->load([
                'producto',
                'receta',
                'loteProducto',
                'user',
                'consumos.loteInsumo',
                'consumos.insumo',
            ]);
        });
    }

    // ---------- Internos ----------

    private function obtenerRecetaActiva(Producto $producto): Receta
    {
        $receta = $producto->recetas()
            ->activas()
            ->with('insumos')
            ->latest('id')
            ->first();

        if ($receta === null) {
            throw RecetaNoDisponibleException::sinRecetaActiva($producto);
        }

        if ($receta->insumos->isEmpty()) {
            throw RecetaNoDisponibleException::recetaIncompleta($receta, 'no tiene insumos registrados.');
        }

        if (Cantidad::aMilesimas($receta->rendimiento_base) <= 0) {
            throw RecetaNoDisponibleException::recetaIncompleta($receta, 'el rendimiento base debe ser mayor a cero.');
        }

        return $receta;
    }

    private function crearOrdenPendiente(
        Producto $producto,
        Receta $receta,
        int $cantidad,
        int $userId,
        Carbon $fecha,
    ): OrdenProduccion {
        // Código temporal único; se reemplaza por uno legible basado en el id
        // (evita colisiones de correlativos con órdenes simultáneas).
        $orden = OrdenProduccion::create([
            'codigo_orden' => 'TMP-' . Str::ulid(),
            'producto_id' => $producto->id,
            'receta_id' => $receta->id,
            'cantidad_producida' => Cantidad::aDecimal($cantidad),
            'estado' => OrdenProduccion::ESTADO_PENDIENTE,
            'user_id' => $userId,
            'fecha_produccion' => $fecha,
        ]);

        $orden->update([
            'codigo_orden' => sprintf('OP-%s-%05d', $fecha->format('Ymd'), $orden->id),
        ]);

        return $orden;
    }

    /**
     * cantidad_insumo = cantidad_receta × (cantidad_a_producir / rendimiento_base)
     * Todo en milésimas. Mínimo 0.001 para que un insumo nunca se omita por redondeo.
     */
    private function escalarCantidad(string|float $cantidadReceta, int $cantidadAProducir, string|float $rendimientoBase): int
    {
        $base = Cantidad::aMilesimas($cantidadReceta);
        $rendimiento = Cantidad::aMilesimas($rendimientoBase);

        return max(1, (int) round(($base * $cantidadAProducir) / $rendimiento));
    }

    private function normalizarCodigoLote(?string $codigo): ?string
    {
        if ($codigo === null || trim($codigo) === '') {
            return null;
        }

        $codigo = trim($codigo);

        if (mb_strlen($codigo) > 50) {
            throw new InvalidArgumentException('El código de lote no puede superar los 50 caracteres.');
        }

        return $codigo;
    }
}
