<?php

namespace App\Services;

use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Support\Cantidad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Recepción de compras: cada ingreso crea un lote de insumo nuevo,
 * vinculado a su proveedor, con su movimiento ENTRADA_COMPRA en el Kardex.
 */
class IngresoInsumoService
{
    public function __construct(
        private readonly LoteService $loteService,
        private readonly AlertaService $alertaService,
    ) {}

    /**
     * @param  array{
     *     insumo_id: int,
     *     proveedor_id: int,
     *     cantidad: float|int|string,
     *     fecha_vencimiento: string,
     *     fecha_fabricacion?: ?string,
     *     codigo_lote?: ?string,
     *     codigo_lote_proveedor?: ?string,
     *     documento_referencia?: ?string,
     *     observaciones?: ?string,
     * }  $data
     *
     * @throws InvalidArgumentException Datos incoherentes o código de lote duplicado
     */
    public function registrarEntradaInsumo(array $data, int $userId): MovimientoInventario
    {
        $cantidad = Cantidad::aMilesimas($data['cantidad'] ?? 0);

        if ($cantidad <= 0) {
            throw new InvalidArgumentException('La cantidad ingresada debe ser mayor a cero.');
        }

        $hoy = now()->startOfDay();
        $vencimiento = Carbon::parse($data['fecha_vencimiento'] ?? throw new InvalidArgumentException('La fecha de vencimiento es obligatoria.'))->startOfDay();
        $fabricacion = empty($data['fecha_fabricacion']) ? $hoy->copy() : Carbon::parse($data['fecha_fabricacion'])->startOfDay();

        if ($vencimiento->lt($hoy)) {
            throw new InvalidArgumentException(sprintf(
                'No se puede ingresar un lote vencido (venció el %s). Debe rechazarse en recepción.',
                $vencimiento->toDateString(),
            ));
        }

        if ($fabricacion->gt($hoy)) {
            throw new InvalidArgumentException('La fecha de fabricación no puede ser futura.');
        }

        if ($vencimiento->lt($fabricacion)) {
            throw new InvalidArgumentException('La fecha de vencimiento no puede ser anterior a la de fabricación.');
        }

        $codigoLote = $this->textoOpcional($data['codigo_lote'] ?? null);

        return DB::transaction(function () use ($data, $userId, $cantidad, $hoy, $vencimiento, $fabricacion, $codigoLote) {
            $insumo = Insumo::query()->lockForUpdate()->findOrFail((int) $data['insumo_id']);
            $proveedor = Proveedor::query()->findOrFail((int) $data['proveedor_id']);

            if ($codigoLote !== null && Lote::where('codigo_lote', $codigoLote)->exists()) {
                throw new InvalidArgumentException("El código de lote \"{$codigoLote}\" ya está registrado.");
            }

            $lote = Lote::create([
                'codigo_lote' => $codigoLote ?? 'TMP-' . Str::ulid(),
                'codigo_lote_proveedor' => $this->textoOpcional($data['codigo_lote_proveedor'] ?? null),
                'tipo_item' => Lote::TIPO_INSUMO,
                'insumo_id' => $insumo->id,
                'proveedor_id' => $proveedor->id,
                'fecha_fabricacion' => $fabricacion->toDateString(),
                'fecha_vencimiento' => $vencimiento->toDateString(),
                'cantidad_inicial' => Cantidad::aDecimal($cantidad),
                'cantidad_actual' => Cantidad::aDecimal($cantidad),
                'estado' => Lote::ESTADO_ACTIVO,
            ]);

            // Código interno legible y sin colisiones: basado en el id del lote
            if ($codigoLote === null) {
                $lote->update([
                    'codigo_lote' => sprintf('%s-%s-%05d', $insumo->codigo, $hoy->format('Ymd'), $lote->id),
                ]);
            }

            $movimiento = MovimientoInventario::create([
                'tipo_movimiento' => MovimientoInventario::ENTRADA_COMPRA,
                'lote_id' => $lote->id,
                'cantidad' => Cantidad::aDecimal($cantidad),
                'motivo_observacion' => $this->motivo($proveedor, $data),
                'user_id' => $userId,
            ]);

            $this->loteService->recalcularStock($insumo);
            $this->loteService->evaluarStockMinimo($insumo); // resuelve alertas de mínimo si se repuso

            // Un lote que llega dentro de su ventana preventiva se alerta de inmediato
            $this->alertaService->evaluarLote($lote);

            return $movimiento->load(['lote.insumo', 'lote.proveedor', 'user']);
        });
    }

    // ---------- Internos ----------

    private function motivo(Proveedor $proveedor, array $data): string
    {
        $partes = ["Compra a {$proveedor->razon_social}"];

        if ($documento = $this->textoOpcional($data['documento_referencia'] ?? null)) {
            $partes[] = "Doc. {$documento}";
        }

        if ($loteProveedor = $this->textoOpcional($data['codigo_lote_proveedor'] ?? null)) {
            $partes[] = "Lote proveedor {$loteProveedor}";
        }

        if ($observaciones = $this->textoOpcional($data['observaciones'] ?? null)) {
            $partes[] = $observaciones;
        }

        return implode(' · ', $partes);
    }

    private function textoOpcional(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}
