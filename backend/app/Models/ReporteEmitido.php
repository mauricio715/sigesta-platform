<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReporteEmitido extends Model
{
    protected $table = 'reportes_emitidos';

    protected $fillable = ['codigo', 'huella', 'tipo', 'formato', 'titulo', 'parametros', 'user_id'];

    protected function casts(): array
    {
        return ['parametros' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
