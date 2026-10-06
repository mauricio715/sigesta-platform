<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Despacho;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\OrdenProduccion;
use App\Models\ProduccionConsumo;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Support\Cantidad;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Trazabilidad bidireccional (HACCP / ISO 22000):
 *   hacia adelante: lote de insumo -> órdenes -> lotes de producto -> despachos -> clientes
 *   hacia atrás:    lote de producto -> orden -> receta -> lotes de insumo -> proveedores
 *
 * Devuelve arreglos listos para serializar a JSON (API y contexto del asistente IA).
 */
class TrazabilidadService
{
    /**
     * Ante un insumo sospechoso: ¿en qué se usó y a qué clientes llegó?
     */
    public function rastrearInsumoHaciaAdelante(int $loteInsumoId): array
    {
        $lote = Lote::with(['insumo', 'proveedor'])->findOrFail($loteInsumoId);

        if ($lote->tipo_item !== Lote::TIPO_INSUMO) {
            throw new InvalidArgumentException("El lote {$lote->codigo_lote} no es un lote de insumo.");
        }

        $consumos = ProduccionConsumo::query()
            ->where('lote_insumo_id', $lote->id)
            ->with([
                'ordenProduccion.producto',
                'ordenProduccion.loteProducto.despachoDetalles' => fn ($q) => $q->orderBy('id'),
                'ordenProduccion.loteProducto.despachoDetalles.despacho.cliente',
            ])
            ->orderBy('id')
            ->get();

        $producciones = [];
        $clientes = [];
        $codigosDespacho = [];

        foreach ($consumos as $consumo) {
            $orden = $consumo->ordenProduccion;
            $loteProducto = $orden->loteProducto;
            $despachos = $loteProducto ? $this->despachosDeLote($loteProducto) : [];

            foreach ($despachos as $despacho) {
                $codigosDespacho[$despacho['codigo_despacho']] = true;

                // Un despacho anulado no llegó al cliente
                if ($despacho['estado'] !== Despacho::ESTADO_COMPLETADO) {
                    continue;
                }

                $clienteId = $despacho['cliente']['id'];
                $clientes[$clienteId] ??= $despacho['cliente'] + ['entregas' => []];
                $clientes[$clienteId]['entregas'][] = [
                    'codigo_despacho' => $despacho['codigo_despacho'],
                    'fecha_despacho' => $despacho['fecha_despacho'],
                    'producto' => $orden->producto->nombre,
                    'lote_producto' => $loteProducto->codigo_lote,
                    'cantidad' => $despacho['cantidad'],
                ];
            }

            $producciones[] = [
                'orden_produccion' => $this->resumenOrden($orden),
                'cantidad_insumo_consumida' => $consumo->cantidad_consumida,
                'producto' => $this->resumenProducto($orden->producto),
                'lote_producto' => $loteProducto ? $this->resumenLote($loteProducto) : null,
                'despachos' => $despachos,
            ];
        }

        return [
            'lote_insumo' => $this->resumenLote($lote) + [
                'insumo' => $this->resumenInsumo($lote->insumo),
                'proveedor' => $this->resumenProveedor($lote->proveedor),
            ],
            'producciones' => $producciones,
            'clientes_afectados' => array_values($clientes),
            'resumen' => [
                'ordenes_produccion' => count($producciones),
                'lotes_producto' => count(array_filter(array_column($producciones, 'lote_producto'))),
                'despachos' => count($codigosDespacho),
                'clientes_afectados' => count($clientes),
            ],
        ];
    }

    /**
     * Ante un producto observado: ¿con qué se fabricó y de qué proveedores vino?
     */
    public function rastrearProductoHaciaAtras(int $loteProductoId): array
    {
        $lote = Lote::with([
            'producto',
            'ordenProduccion.user',
            'ordenProduccion.receta.insumos',
            // Orden explícito: el índice único (orden, lote) podría devolverlos ordenados por lote
            'ordenProduccion.consumos' => fn ($q) => $q->orderBy('id'),
            'ordenProduccion.consumos.insumo',
            'ordenProduccion.consumos.loteInsumo.proveedor',
        ])->findOrFail($loteProductoId);

        if ($lote->tipo_item !== Lote::TIPO_PRODUCTO) {
            throw new InvalidArgumentException("El lote {$lote->codigo_lote} no es un lote de producto terminado.");
        }

        $orden = $lote->ordenProduccion;

        return [
            'lote_producto' => $this->resumenLote($lote) + [
                'producto' => $this->resumenProducto($lote->producto),
            ],
            'orden_produccion' => $orden ? $this->resumenOrden($orden) + [
                'responsable' => $orden->user->name,
            ] : null,
            'receta' => $orden ? [
                'id' => $orden->receta->id,
                'nombre_receta' => $orden->receta->nombre_receta,
                'rendimiento_base' => $orden->receta->rendimiento_base,
                'formula' => $orden->receta->insumos->map(fn (Insumo $insumo) => [
                    'insumo' => $insumo->nombre,
                    'codigo' => $insumo->codigo,
                    'cantidad_requerida' => $insumo->pivot->cantidad_requerida,
                    'unidad_medida' => $insumo->unidad_medida,
                ])->all(),
            ] : null,
            'insumos_origen' => $orden
                ? $orden->consumos->map(fn (ProduccionConsumo $consumo) => [
                    'insumo' => $this->resumenInsumo($consumo->insumo),
                    'lote' => $this->resumenLote($consumo->loteInsumo),
                    'proveedor' => $this->resumenProveedor($consumo->loteInsumo->proveedor),
                    'cantidad_consumida' => $consumo->cantidad_consumida,
                ])->all()
                : [],
        ];
    }

    // ---------- Internos ----------

    /** Despachos de un lote de producto, agrupados por despacho */
    private function despachosDeLote(Lote $loteProducto): array
    {
        return $loteProducto->despachoDetalles
            ->groupBy('despacho_id')
            ->map(function (Collection $detalles) {
                $despacho = $detalles->first()->despacho;
                $total = $detalles->sum(fn ($detalle) => Cantidad::aMilesimas($detalle->cantidad));

                return [
                    'codigo_despacho' => $despacho->codigo_despacho,
                    'fecha_despacho' => $despacho->fecha_despacho->toDateTimeString(),
                    'estado' => $despacho->estado,
                    'cantidad' => Cantidad::aDecimal($total),
                    'cliente' => $this->resumenCliente($despacho->cliente),
                ];
            })
            ->values()
            ->all();
    }

    private function resumenLote(Lote $lote): array
    {
        return [
            'id' => $lote->id,
            'codigo_lote' => $lote->codigo_lote,
            'tipo_item' => $lote->tipo_item,
            'fecha_fabricacion' => $lote->fecha_fabricacion->toDateString(),
            'fecha_vencimiento' => $lote->fecha_vencimiento->toDateString(),
            'cantidad_inicial' => $lote->cantidad_inicial,
            'cantidad_actual' => $lote->cantidad_actual,
            'estado' => $lote->estado,
        ];
    }

    private function resumenOrden(OrdenProduccion $orden): array
    {
        return [
            'id' => $orden->id,
            'codigo_orden' => $orden->codigo_orden,
            'fecha_produccion' => $orden->fecha_produccion->toDateTimeString(),
            'cantidad_producida' => $orden->cantidad_producida,
            'estado' => $orden->estado,
        ];
    }

    private function resumenInsumo(Insumo $insumo): array
    {
        return [
            'id' => $insumo->id,
            'codigo' => $insumo->codigo,
            'nombre' => $insumo->nombre,
            'unidad_medida' => $insumo->unidad_medida,
        ];
    }

    private function resumenProducto(Producto $producto): array
    {
        return [
            'id' => $producto->id,
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'unidad_medida' => $producto->unidad_medida,
        ];
    }

    private function resumenCliente(Cliente $cliente): array
    {
        return [
            'id' => $cliente->id,
            'codigo_cliente' => $cliente->codigo_cliente,
            'razon_social' => $cliente->razon_social,
            'nit_ci' => $cliente->nit_ci,
            'telefono' => $cliente->telefono,
            'email' => $cliente->email,
            'direccion' => $cliente->direccion,
        ];
    }

    private function resumenProveedor(?Proveedor $proveedor): ?array
    {
        if ($proveedor === null) {
            return null;
        }

        return [
            'id' => $proveedor->id,
            'codigo_proveedor' => $proveedor->codigo_proveedor,
            'razon_social' => $proveedor->razon_social,
            'nit' => $proveedor->nit,
            'telefono' => $proveedor->telefono,
        ];
    }
}
