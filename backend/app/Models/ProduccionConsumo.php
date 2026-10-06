<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProduccionConsumo extends Model
{
    use HasFactory;

    protected $table = 'produccion_consumos';

    protected $fillable = [
        'orden_produccion_id',
        'lote_insumo_id',
        'insumo_id',
        'cantidad_consumida',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_consumida' => 'decimal:3',
        ];
    }

    /**
     * Evidencia de trazabilidad: igual que el Kardex, no se edita ni se borra.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los registros de trazabilidad son inmutables.'));
        static::deleting(fn () => throw new LogicException('Los registros de trazabilidad no pueden eliminarse.'));
    }

    // ---------- Relaciones ----------

    public function ordenProduccion(): BelongsTo
    {
        return $this->belongsTo(OrdenProduccion::class);
    }

    public function loteInsumo(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_insumo_id');
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }
}
