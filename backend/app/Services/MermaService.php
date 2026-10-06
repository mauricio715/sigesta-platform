<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bajas de inventario de un lote específico (no aplica FEFO: se da de baja
 * exactamente el lote afectado).
 *   - VENCIMIENTO   => movimiento BAJA_VENCIMIENTO (solo lotes ya vencidos)
 *   - Otros motivos => movimiento MERMA_DESECHO
 */
class MermaService
{
    public const MOTIVO_VENCIMIENTO = 'VENCIMIENTO';

    public const MOTIVOS = [
        self::MOTIVO_VENCIMIENTO,
        'DETERIORO',
        'CONTAMINACION',
        'DANO_EMPAQUE',
        'ERROR_PROCESO',
        'CONTROL_CALIDAD',
        'OTRO',
    ];

    public function __construct(
        private readonly LoteService $loteService,
    ) {}

    /**
     * @throws InvalidArgumentException Motivo inválido, lote sin saldo, cantidad excedida
     *                                  o baja por vencimiento de un lote vigente
     */
    public function registrarMerma(
        int $loteId,
        float $cantidad,
        string $motivo,
        int $userId,
        ?string $observacion = null,
    ): MovimientoInventario {
        $baja = Cantidad::aMilesimas($cantidad);
        $observacion = $observacion === null ? null : (trim($observacion) ?: null);

        if ($baja <= 0) {
            throw new InvalidArgumentException('La cantidad a dar de baja debe ser mayor a cero.');
        }

        if (! in_array($motivo, self::MOTIVOS, true)) {
            throw new InvalidArgumentException('Motivo de baja no válido. Opciones: ' . implode(', ', self::MOTIVOS) . '.');
        }

        if ($motivo === 'OTRO' && $observacion === null) {
            throw new InvalidArgumentException('Debe detallar la observación cuando el motivo es OTRO.');
        }

        return DB::transaction(function () use ($loteId, $baja, $motivo, $userId, $observacion) {
            $lote = Lote::query()->lockForUpdate()->findOrFail($loteId);
            $saldo = Cantidad::aMilesimas($lote->cantidad_actual);

            if ($saldo === 0 || $lote->estado === Lote::ESTADO_AGOTADO) {
                throw new InvalidArgumentException("El lote {$lote->codigo_lote} no tiene saldo disponible.");
            }

            if ($baja > $saldo) {
                throw new InvalidArgumentException(sprintf(
                    'La cantidad a dar de baja (%s) supera el saldo del lote %s (%s).',
                    Cantidad::aDecimal($baja),
                    $lote->codigo_lote,
                    Cantidad::aDecimal($saldo),
                ));
            }

            $vencido = $lote->estado === Lote::ESTADO_VENCIDO || $lote->estaVencido();

            if ($motivo === self::MOTIVO_VENCIMIENTO && ! $vencido) {
                throw new InvalidArgumentException(sprintf(
                    'El lote %s aún no ha vencido (vence el %s). Use otro motivo de baja.',
                    $lote->codigo_lote,
                    $lote->fecha_vencimiento->toDateString(),
                ));
            }

            $nuevoSaldo = $saldo - $baja;
            $lote->cantidad_actual = Cantidad::aDecimal($nuevoSaldo);

            if ($nuevoSaldo === 0) {
                $lote->estado = Lote::ESTADO_AGOTADO;
            }

            $lote->save();

            $movimiento = MovimientoInventario::create([
                'tipo_movimiento' => $motivo === self::MOTIVO_VENCIMIENTO
                    ? MovimientoInventario::BAJA_VENCIMIENTO
                    : MovimientoInventario::MERMA_DESECHO,
                'lote_id' => $lote->id,
                'cantidad' => Cantidad::aDecimal($baja),
                'categoria_merma' => $motivo,
                'motivo_observacion' => $observacion ?? 'Baja por ' . strtolower(str_replace('_', ' ', $motivo)),
                'user_id' => $userId,
            ]);

            // Un lote dado de baja por completo ya no requiere atención
            if ($nuevoSaldo === 0) {
                Alerta::query()
                    ->where('lote_id', $lote->id)
                    ->whereIn('tipo_alerta', [Alerta::PREVENTIVA_VENCIMIENTO, Alerta::VENCIMIENTO_CRITICO])
                    ->where('leida', false)
                    ->update(['leida' => true]);
            }

            if ($item = $lote->item()) {
                $this->loteService->recalcularStock($item);
                $this->loteService->evaluarStockMinimo($item);
            }

            return $movimiento->load(['lote.insumo', 'lote.producto', 'lote.proveedor', 'user']);
        });
    }
}
