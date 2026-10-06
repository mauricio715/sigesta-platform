<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite versionar fórmulas: un producto puede tener recetas
     * históricas inactivas y una sola receta activa para producir.
     */
    public function up(): void
    {
        Schema::table('recetas', function (Blueprint $table) {
            $table->boolean('activa')->default(true)->after('observaciones');
            $table->index(['producto_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::table('recetas', function (Blueprint $table) {
            $table->dropIndex(['producto_id', 'activa']);
            $table->dropColumn('activa');
        });
    }
};
