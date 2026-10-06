<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo_movimiento', [
                'ENTRADA_COMPRA',
                'CONSUMO_PRODUCCION',
                'INGRESO_PRODUCCION',
                'SALIDA_VENTA',
                'MERMA_DESECHO',
            ]);
            $table->foreignId('lote_id')->constrained('lotes');
            // Siempre positiva; el sentido (entrada/salida) lo define tipo_movimiento
            $table->decimal('cantidad', 12, 3);
            $table->text('motivo_observacion')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->index(['lote_id', 'created_at']);
            $table->index('tipo_movimiento');
        });

        DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT chk_mov_cantidad CHECK (cantidad > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
