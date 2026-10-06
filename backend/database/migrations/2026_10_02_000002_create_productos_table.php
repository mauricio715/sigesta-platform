<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->enum('unidad_medida', ['kg', 'gr', 'lt', 'ml', 'unidad']);
            $table->unsignedInteger('dias_vida_util');
            // Necesario para la "Alerta de Reorden" de productos terminados (doc. maestro, módulo 7)
            $table->decimal('stock_minimo', 12, 3)->default(0);
            $table->decimal('stock_actual', 12, 3)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
