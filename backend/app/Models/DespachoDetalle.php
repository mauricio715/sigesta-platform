<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DespachoDetalle extends Model
{
    use HasFactory;

    protected $table = 'despacho_detalles';

    protected $fillable = [
        'despacho_id',
        'producto_id',
        'lote_producto_id',
        'cantidad',
        'precio_unitario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:3',
        ];
    }

    /** Evidencia de trazabilidad hacia el cliente: no se edita ni se borra */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los detalles de despacho son inmutables.'));
        static::deleting(fn () => throw new LogicException('Los detalles de despacho no pueden eliminarse.'));
    }

    // ---------- Relaciones ----------

    public function despacho(): BelongsTo
    {
        return $this->belongsTo(Despacho::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function loteProducto(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_producto_id');
    }
}
