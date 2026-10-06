<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_produccion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_orden', 40)->unique();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('receta_id')->constrained('recetas');
            $table->decimal('cantidad_producida', 12, 3);
            // Nullable mientras la orden está PENDIENTE; único: un lote proviene de una sola orden
            $table->foreignId('lote_producto_id')->nullable()->unique()->constrained('lotes');
            $table->enum('estado', ['PENDIENTE', 'COMPLETADA', 'CANCELADA'])->default('PENDIENTE');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('fecha_produccion');
            $table->timestamps();

            $table->index(['estado', 'fecha_produccion']);
        });

        DB::statement('ALTER TABLE ordenes_produccion ADD CONSTRAINT chk_op_cantidad CHECK (cantidad_producida > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_produccion');
    }
};
