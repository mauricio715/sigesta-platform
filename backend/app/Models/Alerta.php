<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alerta extends Model
{
    use HasFactory;

    public const PREVENTIVA_VENCIMIENTO = 'PREVENTIVA_VENCIMIENTO';
    public const VENCIMIENTO_CRITICO = 'VENCIMIENTO_CRITICO';
    public const STOCK_MINIMO = 'STOCK_MINIMO';

    public const PRIORIDAD_BAJA = 'BAJA';
    public const PRIORIDAD_MEDIA = 'MEDIA';
    public const PRIORIDAD_ALTA = 'ALTA';

    protected $table = 'alertas';

    protected $fillable = [
        'lote_id',
        'insumo_id',
        'producto_id',
        'tipo_alerta',
        'mensaje',
        'leida',
        'nivel_prioridad',
    ];

    protected function casts(): array
    {
        return [
            'leida' => 'boolean',
        ];
    }

    // ---------- Relaciones ----------

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    // ---------- Scopes ----------

    public function scopeNoLeidas(Builder $query): Builder
    {
        return $query->where('leida', false);
    }
}
