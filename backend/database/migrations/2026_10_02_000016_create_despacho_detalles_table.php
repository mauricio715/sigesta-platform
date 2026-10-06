<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despacho_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despacho_id')->constrained('despachos');
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('lote_producto_id')->constrained('lotes');
            $table->decimal('cantidad', 12, 3);
            // Nullable: el catálogo aún no maneja precios de venta
            $table->decimal('precio_unitario', 12, 3)->nullable();
            $table->timestamps();

            // Un lote aparece una sola vez por despacho
            $table->unique(['despacho_id', 'lote_producto_id'], 'detalle_despacho_lote_unique');
        });

        DB::statement('
            ALTER TABLE despacho_detalles
            ADD CONSTRAINT chk_detalle_cantidad CHECK (cantidad > 0),
            ADD CONSTRAINT chk_detalle_precio CHECK (precio_unitario IS NULL OR precio_unitario >= 0)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('despacho_detalles');
    }
};
