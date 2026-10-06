<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            // lote_id es nullable: STOCK_MINIMO se refiere a un insumo/producto, no a un lote
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->cascadeOnDelete();
            $table->foreignId('insumo_id')->nullable()->constrained('insumos')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->cascadeOnDelete();
            $table->enum('tipo_alerta', ['PREVENTIVA_VENCIMIENTO', 'VENCIMIENTO_CRITICO', 'STOCK_MINIMO']);
            $table->string('mensaje', 500);
            $table->boolean('leida')->default(false);
            $table->enum('nivel_prioridad', ['BAJA', 'MEDIA', 'ALTA'])->default('MEDIA');
            $table->timestamps();

            $table->index(['leida', 'nivel_prioridad']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
    }
};
