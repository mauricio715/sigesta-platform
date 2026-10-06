<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use HasFactory;

    protected $table = 'proveedores';

    protected $fillable = [
        'codigo_proveedor',
        'razon_social',
        'nit',
        'direccion',
        'telefono',
        'email',
    ];

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }
}
