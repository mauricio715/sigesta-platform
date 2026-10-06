<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lote extends Model
{
    use HasFactory;

    public const TIPO_INSUMO = 'INSUMO';
    public const TIPO_PRODUCTO = 'PRODUCTO';

    public const ESTADO_ACTIVO = 'ACTIVO';
    public const ESTADO_PROXIMO_A_VENCER = 'PROXIMO_A_VENCER';
    public const ESTADO_VENCIDO = 'VENCIDO';
    public const ESTADO_AGOTADO = 'AGOTADO';
    /** Ingreso registrado por error y anulado: saldo cero, fuera de todo cálculo */
    public const ESTADO_ANULADO = 'ANULADO';

    /** Estados desde los que un lote puede consumirse o despacharse */
    public const ESTADOS_DISPONIBLES = [
        self::ESTADO_ACTIVO,
        self::ESTADO_PROXIMO_A_VENCER,
    ];

    protected $table = 'lotes';

    protected $fillable = [
        'codigo_lote',
        'codigo_lote_proveedor',
        'tipo_item',
        'insumo_id',
        'producto_id',
        'proveedor_id',
        'fecha_fabricacion',
        'fecha_vencimiento',
        'cantidad_inicial',
        'cantidad_actual',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_fabricacion' => 'date',
            'fecha_vencimiento' => 'date',
            'cantidad_inicial' => 'decimal:3',
            'cantidad_actual' => 'decimal:3',
        ];
    }

    // ---------- Relaciones base ----------

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class);
    }

    // ---------- Trazabilidad (lotes de PRODUCTO) ----------

    /** Orden que fabricó este lote de producto terminado */
    public function ordenProduccion(): HasOne
    {
        return $this->hasOne(OrdenProduccion::class, 'lote_producto_id');
    }

    /** Hacia atrás: consumos de insumo que originaron este lote de producto */
    public function consumosOrigen(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProduccionConsumo::class,
            OrdenProduccion::class,
            'lote_producto_id',
            'orden_produccion_id',
            'id',
            'id',
        );
    }

    /** Hacia adelante: despachos a clientes en los que salió este lote de producto */
    public function despachoDetalles(): HasMany
    {
        return $this->hasMany(DespachoDetalle::class, 'lote_producto_id');
    }

    // ---------- Trazabilidad (lotes de INSUMO) ----------

    /** Hacia adelante: órdenes de producción en las que se usó este lote de insumo */
    public function consumosEnProduccion(): HasMany
    {
        return $this->hasMany(ProduccionConsumo::class, 'lote_insumo_id');
    }

    // ---------- Scopes ----------

    /** Lotes con saldo y no vencidos (los únicos que pueden salir) */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_DISPONIBLES)
            ->where('cantidad_actual', '>', 0)
            ->whereDate('fecha_vencimiento', '>=', now()->toDateString());
    }

    /** Orden FEFO: primero el que vence antes; desempate por orden de registro */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderBy('fecha_vencimiento')->orderBy('id');
    }

    // ---------- Helpers ----------

    /** Devuelve el Insumo o Producto al que pertenece el lote */
    public function item(): Insumo|Producto|null
    {
        return $this->tipo_item === self::TIPO_INSUMO ? $this->insumo : $this->producto;
    }

    public function estaVencido(): bool
    {
        return $this->fecha_vencimiento->isBefore(now()->startOfDay());
    }
}
