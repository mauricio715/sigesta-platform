<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    protected $fillable = [
        'codigo_cliente',
        'razon_social',
        'nit_ci',
        'direccion',
        'telefono',
        'email',
    ];

    public function despachos(): HasMany
    {
        return $this->hasMany(Despacho::class);
    }
}
