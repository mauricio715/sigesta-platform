<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de cada reporte emitido: quién, cuándo, con qué parámetros
     * y la huella SHA-256 de su contenido. El código impreso en el documento
     * permite verificar que fue emitido por el sistema.
     */
    public function up(): void
    {
        Schema::create('reportes_emitidos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->char('huella', 64);
            $table->string('tipo', 30);
            $table->enum('formato', ['pdf', 'xlsx']);
            $table->string('titulo', 200);
            $table->json('parametros')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->index(['tipo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_emitidos');
    }
};
