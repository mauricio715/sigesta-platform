<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenProduccion extends Model
{
    use HasFactory;

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_COMPLETADA = 'COMPLETADA';
    public const ESTADO_CANCELADA = 'CANCELADA';

    protected $table = 'ordenes_produccion';

    protected $fillable = [
        'codigo_orden',
        'producto_id',
        'receta_id',
        'cantidad_producida',
        'lote_producto_id',
        'estado',
        'user_id',
        'fecha_produccion',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_producida' => 'decimal:3',
            'fecha_produccion' => 'datetime',
        ];
    }

    // ---------- Relaciones ----------

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class);
    }

    /** Lote de producto terminado generado por esta orden */
    public function loteProducto(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_producto_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lotes de insumo consumidos (trazabilidad hacia atrás), en el orden
     * en que se consumieron. El orderBy es explícito porque el índice único
     * (orden, lote) podría devolverlos ordenados por lote.
     */
    public function consumos(): HasMany
    {
        return $this->hasMany(ProduccionConsumo::class)->orderBy('id');
    }
}
