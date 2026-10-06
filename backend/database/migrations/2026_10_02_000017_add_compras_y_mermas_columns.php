<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - BAJA_VENCIMIENTO: separa en el Kardex la baja de lotes vencidos del resto
     *   de mermas (MERMA_DESECHO), para reportes de inocuidad.
     * - categoria_merma: motivo normalizado de la baja (DETERIORO, CONTAMINACION...).
     * - codigo_lote_proveedor: número de lote impreso por el proveedor; es el que
     *   el proveedor citará si emite un retiro (recall) de su producto.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE movimientos_inventario MODIFY tipo_movimiento ENUM(
                'ENTRADA_COMPRA', 'CONSUMO_PRODUCCION', 'INGRESO_PRODUCCION',
                'SALIDA_VENTA', 'MERMA_DESECHO', 'BAJA_VENCIMIENTO'
            ) NOT NULL
        ");

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->string('categoria_merma', 30)->nullable()->after('motivo_observacion');
        });

        Schema::table('lotes', function (Blueprint $table) {
            $table->string('codigo_lote_proveedor', 50)->nullable()->after('codigo_lote')->index();
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropIndex(['codigo_lote_proveedor']);
            $table->dropColumn('codigo_lote_proveedor');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropColumn('categoria_merma');
        });

        DB::statement("
            ALTER TABLE movimientos_inventario MODIFY tipo_movimiento ENUM(
                'ENTRADA_COMPRA', 'CONSUMO_PRODUCCION', 'INGRESO_PRODUCCION',
                'SALIDA_VENTA', 'MERMA_DESECHO'
            ) NOT NULL
        ");
    }
};
