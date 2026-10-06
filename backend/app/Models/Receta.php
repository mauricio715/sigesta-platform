<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receta extends Model
{
    use HasFactory;

    protected $table = 'recetas';

    protected $fillable = [
        'producto_id',
        'nombre_receta',
        'rendimiento_base',
        'observaciones',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'rendimiento_base' => 'decimal:3',
            'activa' => 'boolean',
        ];
    }

    // ---------- Relaciones ----------

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Ingredientes del BOM, en el orden en que se registraron.
     * Cantidad: $insumo->pivot->cantidad_requerida
     */
    public function insumos(): BelongsToMany
    {
        return $this->belongsToMany(Insumo::class, 'receta_insumo')
            ->withPivot('cantidad_requerida')
            ->withTimestamps()
            ->orderBy('receta_insumo.id');
    }

    public function ordenesProduccion(): HasMany
    {
        return $this->hasMany(OrdenProduccion::class);
    }

    // ---------- Scopes ----------

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }
}
