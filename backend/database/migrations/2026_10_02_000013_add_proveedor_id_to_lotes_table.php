<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Proveedor de origen de los lotes de insumo (trazabilidad hacia atrás) */
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->foreignId('proveedor_id')->nullable()->after('producto_id')->constrained('proveedores');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_id');
        });
    }
};
