<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despachos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_despacho', 40)->unique();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('fecha_despacho');
            $table->text('observaciones')->nullable();
            $table->enum('estado', ['COMPLETADO', 'ANULADO'])->default('COMPLETADO');
            $table->timestamps();

            $table->index(['cliente_id', 'fecha_despacho']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despachos');
    }
};
