<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Despacho extends Model
{
    use HasFactory;

    public const ESTADO_COMPLETADO = 'COMPLETADO';
    public const ESTADO_ANULADO = 'ANULADO';

    protected $table = 'despachos';

    protected $fillable = [
        'codigo_despacho',
        'cliente_id',
        'user_id',
        'fecha_despacho',
        'observaciones',
        'estado',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_despacho' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    // ---------- Relaciones ----------

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Administrador que anuló el despacho */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DespachoDetalle::class)->orderBy('id');
    }
}
