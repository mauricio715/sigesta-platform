<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MovimientoInventario extends Model
{
    use HasFactory;

    public const ENTRADA_COMPRA = 'ENTRADA_COMPRA';
    public const CONSUMO_PRODUCCION = 'CONSUMO_PRODUCCION';
    public const INGRESO_PRODUCCION = 'INGRESO_PRODUCCION';
    public const SALIDA_VENTA = 'SALIDA_VENTA';
    public const MERMA_DESECHO = 'MERMA_DESECHO';
    public const BAJA_VENCIMIENTO = 'BAJA_VENCIMIENTO';
    /** Compensatorio: devuelve al lote lo que salía en un despacho anulado */
    public const DEVOLUCION_CLIENTE = 'DEVOLUCION_CLIENTE';
    /** Compensatorio: retira el saldo de un ingreso de compra anulado */
    public const ANULACION_COMPRA = 'ANULACION_COMPRA';

    /** Tipos que incrementan el saldo del lote; el resto lo disminuyen */
    public const TIPOS_ENTRADA = [
        self::ENTRADA_COMPRA,
        self::INGRESO_PRODUCCION,
        self::DEVOLUCION_CLIENTE,
    ];

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'tipo_movimiento',
        'lote_id',
        'cantidad',
        'motivo_observacion',
        'categoria_merma',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
        ];
    }

    /**
     * Kardex inmutable: un movimiento no se edita ni se borra;
     * los errores se corrigen con un movimiento compensatorio.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los movimientos de inventario son inmutables.'));
        static::deleting(fn () => throw new LogicException('Los movimientos de inventario no pueden eliminarse.'));
    }

    // ---------- Relaciones ----------

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------- Helpers ----------

    public function esEntrada(): bool
    {
        return in_array($this->tipo_movimiento, self::TIPOS_ENTRADA, true);
    }
}
