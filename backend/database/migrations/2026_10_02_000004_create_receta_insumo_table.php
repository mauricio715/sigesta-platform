<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receta_insumo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receta_id')->constrained('recetas')->cascadeOnDelete();
            $table->foreignId('insumo_id')->constrained('insumos');
            $table->decimal('cantidad_requerida', 12, 3);
            $table->timestamps();

            // Un insumo aparece una sola vez por receta
            $table->unique(['receta_id', 'insumo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receta_insumo');
    }
};
