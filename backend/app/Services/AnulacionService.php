<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Despacho;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\Cantidad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Anulación de registros hechos por error, dentro de un plazo corto
 * (config sigesta.anulacion_horas). El Kardex sigue siendo inmutable:
 * la anulación agrega un movimiento compensatorio con su responsable.
 *
 * Importante para inocuidad: anular un despacho significa que el producto
 * NUNCA salió de la planta. Un producto que regresa de un cliente no debe
 * volver al stock vendible: eso se registra como merma, no como anulación.
 */
class AnulacionService
{
    private const MOTIVO_MINIMO = 10;

    public function __construct(
        private readonly LoteService $loteService,
        private readonly AlertaService $alertaService,
    ) {}

    /**
     * Revierte un despacho: el saldo vuelve a los mismos lotes de los que salió.
     *
     * @throws InvalidArgumentException Ya anulado, fuera de plazo o motivo insuficiente
     */
    public function anularDespacho(int $despachoId, int $userId, string $motivo): Despacho
    {
        $motivo = $this->validarMotivo($motivo);

        return DB::transaction(function () use ($despachoId, $userId, $motivo) {
            $despacho = Despacho::query()->lockForUpdate()->findOrFail($despachoId);

            if ($despacho->estado !== Despacho::ESTADO_COMPLETADO) {
                throw new InvalidArgumentException("El despacho {$despacho->codigo_despacho} ya está anulado.");
            }

            $this->validarPlazo($despacho->created_at, "el despacho {$despacho->codigo_despacho}");

            $detalles = $despacho->detalles()->get();

            // Mismo orden de bloqueo que DespachoService (producto y luego lotes)
            $productos = Producto::query()
                ->whereIn('id', $detalles->pluck('producto_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $concepto = "Anulación del despacho {$despacho->codigo_despacho}: {$motivo}";

            foreach ($detalles as $detalle) {
                $lote = Lote::query()->lockForUpdate()->findOrFail($detalle->lote_producto_id);

                $lote->cantidad_actual = Cantidad::aDecimal(
                    Cantidad::aMilesimas($lote->cantidad_actual) + Cantidad::aMilesimas($detalle->cantidad)
                );

                if ($lote->estado === Lote::ESTADO_AGOTADO) {
                    $lote->estado = Lote::ESTADO_ACTIVO;
                }

                $lote->save();

                MovimientoInventario::create([
                    'tipo_movimiento' => MovimientoInventario::DEVOLUCION_CLIENTE,
                    'lote_id' => $lote->id,
                    'cantidad' => $detalle->cantidad,
                    'motivo_observacion' => $concepto,
                    'user_id' => $userId,
                ]);

                // Si el lote reabierto ya está vencido o por vencer, queda marcado y alertado
                $this->alertaService->evaluarLote($lote);
            }

            $despacho->update([
                'estado' => Despacho::ESTADO_ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => now(),
                'motivo_anulacion' => $motivo,
            ]);

            foreach ($productos as $producto) {
                $this->loteService->recalcularStock($producto);
                $this->loteService->evaluarStockMinimo($producto);
            }

            return $despacho->load(['cliente', 'user', 'anuladoPor', 'detalles.producto', 'detalles.loteProducto']);
        });
    }

    /**
     * Anula el ingreso de compra de un lote de insumo que todavía no se tocó.
     *
     * @throws InvalidArgumentException Lote con consumos/bajas, no es compra, fuera de plazo...
     */
    public function anularIngresoCompra(int $loteId, int $userId, string $motivo): Lote
    {
        $motivo = $this->validarMotivo($motivo);

        return DB::transaction(function () use ($loteId, $userId, $motivo) {
            $loteSinBloqueo = Lote::query()->findOrFail($loteId);

            if ($loteSinBloqueo->tipo_item !== Lote::TIPO_INSUMO) {
                throw new InvalidArgumentException('Solo pueden anularse ingresos de compra (lotes de insumo).');
            }

            // Mismo orden de bloqueo que el consumo FEFO (insumo y luego lote)
            $insumo = Insumo::query()->lockForUpdate()->findOrFail($loteSinBloqueo->insumo_id);
            $lote = Lote::query()->lockForUpdate()->findOrFail($loteId);

            if ($lote->estado === Lote::ESTADO_ANULADO) {
                throw new InvalidArgumentException("El ingreso del lote {$lote->codigo_lote} ya está anulado.");
            }

            $entrada = $lote->movimientos()->where('tipo_movimiento', MovimientoInventario::ENTRADA_COMPRA)->first();

            if ($entrada === null) {
                throw new InvalidArgumentException("El lote {$lote->codigo_lote} no proviene de una compra.");
            }

            $tieneOtrosMovimientos = $lote->movimientos()->where('id', '!=', $entrada->id)->exists();

            if ($tieneOtrosMovimientos || Cantidad::aMilesimas($lote->cantidad_actual) !== Cantidad::aMilesimas($lote->cantidad_inicial)) {
                throw new InvalidArgumentException(
                    "El lote {$lote->codigo_lote} ya fue usado (consumos o bajas registradas) y no puede anularse. "
                    . 'Si el insumo debe retirarse, registre una merma con su justificación.'
                );
            }

            $this->validarPlazo($entrada->created_at, "el ingreso del lote {$lote->codigo_lote}");

            $lote->update([
                'cantidad_actual' => '0.000',
                'estado' => Lote::ESTADO_ANULADO,
            ]);

            MovimientoInventario::create([
                'tipo_movimiento' => MovimientoInventario::ANULACION_COMPRA,
                'lote_id' => $lote->id,
                'cantidad' => $lote->cantidad_inicial,
                'motivo_observacion' => "Anulación del ingreso de compra: {$motivo}",
                'user_id' => $userId,
            ]);

            // Un lote anulado no requiere atención
            Alerta::query()->where('lote_id', $lote->id)->where('leida', false)->update(['leida' => true]);

            $this->loteService->recalcularStock($insumo);
            $this->loteService->evaluarStockMinimo($insumo);

            return $lote->load(['insumo', 'proveedor']);
        });
    }

    // ---------- Reglas comunes ----------

    private function validarMotivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new InvalidArgumentException('Describa el motivo de la anulación (mínimo ' . self::MOTIVO_MINIMO . ' caracteres).');
        }

        return $motivo;
    }

    private function validarPlazo(Carbon $registrado, string $que): void
    {
        $horas = (int) config('sigesta.anulacion_horas', 24);

        if ($registrado->lt(now()->subHours($horas))) {
            throw new InvalidArgumentException(
                "Ya pasaron más de {$horas} horas desde que se registró {$que}, por lo que no puede anularse. "
                . 'Corríjalo con un ajuste (merma) y su justificación.'
            );
        }
    }
}
