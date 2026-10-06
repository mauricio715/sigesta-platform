<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ventana de alerta preventiva como % de la vida útil del lote.
     * Ej.: vida útil de 5 días al 30 % => alerta cuando quedan 2 días o menos;
     *      vida útil de 180 días al 30 % => alerta cuando quedan 54 días o menos.
     */
    public function up(): void
    {
        foreach (['productos', 'insumos'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->decimal('porcentaje_alerta_preventiva', 5, 2)->default(30.00)->after('stock_actual');
            });
        }
    }

    public function down(): void
    {
        foreach (['productos', 'insumos'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('porcentaje_alerta_preventiva');
            });
        }
    }
};
