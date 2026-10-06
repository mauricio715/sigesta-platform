<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_lote', 50)->unique();
            $table->enum('tipo_item', ['INSUMO', 'PRODUCTO']);
            // Sin acción ON DELETE (RESTRICT por defecto): un lote nunca queda huérfano
            $table->foreignId('insumo_id')->nullable()->constrained('insumos');
            $table->foreignId('producto_id')->nullable()->constrained('productos');
            $table->date('fecha_fabricacion');
            $table->date('fecha_vencimiento')->index();
            $table->decimal('cantidad_inicial', 12, 3);
            $table->decimal('cantidad_actual', 12, 3);
            $table->enum('estado', ['ACTIVO', 'PROXIMO_A_VENCER', 'VENCIDO', 'AGOTADO'])->default('ACTIVO');
            $table->timestamps();

            // Índice compuesto para la consulta FEFO típica:
            // WHERE tipo_item = ? AND estado IN (...) ORDER BY fecha_vencimiento
            $table->index(['tipo_item', 'estado', 'fecha_vencimiento'], 'lotes_fefo_idx');
        });

        // Integridad a nivel de motor (MySQL 8.0.16+)
        DB::statement("
            ALTER TABLE lotes
            ADD CONSTRAINT chk_lotes_tipo_item CHECK (
                (tipo_item = 'INSUMO'   AND insumo_id   IS NOT NULL AND producto_id IS NULL) OR
                (tipo_item = 'PRODUCTO' AND producto_id IS NOT NULL AND insumo_id   IS NULL)
            ),
            ADD CONSTRAINT chk_lotes_fechas CHECK (fecha_vencimiento >= fecha_fabricacion),
            ADD CONSTRAINT chk_lotes_cantidades CHECK (
                cantidad_inicial > 0 AND cantidad_actual >= 0 AND cantidad_actual <= cantidad_inicial
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
