<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla puente de trazabilidad:
     *   hacia atrás:    lote de producto -> orden -> consumos -> lotes de insumo
     *   hacia adelante: lote de insumo -> consumos -> orden -> lote de producto
     */
    public function up(): void
    {
        Schema::create('produccion_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_produccion_id')->constrained('ordenes_produccion');
            $table->foreignId('lote_insumo_id')->constrained('lotes');
            $table->foreignId('insumo_id')->constrained('insumos');
            $table->decimal('cantidad_consumida', 12, 3);
            $table->timestamps();

            // Un lote de insumo aparece una sola vez por orden
            $table->unique(['orden_produccion_id', 'lote_insumo_id'], 'consumo_orden_lote_unique');
        });

        DB::statement('ALTER TABLE produccion_consumos ADD CONSTRAINT chk_consumo_cantidad CHECK (cantidad_consumida > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_consumos');
    }
};
