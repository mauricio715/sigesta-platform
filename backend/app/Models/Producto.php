<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'unidad_medida',
        'dias_vida_util',
        'stock_minimo',
        'stock_actual',
        'porcentaje_alerta_preventiva',
    ];

    protected function casts(): array
    {
        return [
            'dias_vida_util' => 'integer',
            'stock_minimo' => 'decimal:3',
            'stock_actual' => 'decimal:3',
            'porcentaje_alerta_preventiva' => 'decimal:2',
        ];
    }

    // ---------- Relaciones ----------

    public function recetas(): HasMany
    {
        return $this->hasMany(Receta::class);
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class);
    }

    public function ordenesProduccion(): HasMany
    {
        return $this->hasMany(OrdenProduccion::class);
    }

    public function despachoDetalles(): HasMany
    {
        return $this->hasMany(DespachoDetalle::class);
    }

    // ---------- Scopes ----------

    public function scopeBajoStockMinimo(Builder $query): Builder
    {
        return $query->whereColumn('stock_actual', '<', 'stock_minimo');
    }
}
