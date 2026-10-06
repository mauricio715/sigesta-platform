<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Insumo extends Model
{
    use HasFactory;

    protected $table = 'insumos';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'unidad_medida',
        'stock_minimo',
        'stock_actual',
        'porcentaje_alerta_preventiva',
    ];

    protected function casts(): array
    {
        return [
            'stock_minimo' => 'decimal:3',
            'stock_actual' => 'decimal:3',
            'porcentaje_alerta_preventiva' => 'decimal:2',
        ];
    }

    // ---------- Relaciones ----------

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    public function recetas(): BelongsToMany
    {
        return $this->belongsToMany(Receta::class, 'receta_insumo')
            ->withPivot('cantidad_requerida')
            ->withTimestamps();
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class);
    }

    public function consumosProduccion(): HasMany
    {
        return $this->hasMany(ProduccionConsumo::class);
    }

    // ---------- Scopes ----------

    public function scopeBajoStockMinimo(Builder $query): Builder
    {
        return $query->whereColumn('stock_actual', '<', 'stock_minimo');
    }
}
