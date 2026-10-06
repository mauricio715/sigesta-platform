<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anulación por error de registro sin romper la inmutabilidad del Kardex:
     * nunca se borra ni edita un movimiento; se agrega uno compensatorio.
     *   - DEVOLUCION_CLIENTE: reingresa al lote lo de un despacho anulado
     *   - ANULACION_COMPRA:   retira el saldo de un ingreso de compra anulado
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE movimientos_inventario MODIFY tipo_movimiento ENUM(
                'ENTRADA_COMPRA', 'CONSUMO_PRODUCCION', 'INGRESO_PRODUCCION', 'SALIDA_VENTA',
                'MERMA_DESECHO', 'BAJA_VENCIMIENTO', 'DEVOLUCION_CLIENTE', 'ANULACION_COMPRA'
            ) NOT NULL
        ");

        DB::statement("
            ALTER TABLE lotes MODIFY estado ENUM(
                'ACTIVO', 'PROXIMO_A_VENCER', 'VENCIDO', 'AGOTADO', 'ANULADO'
            ) NOT NULL DEFAULT 'ACTIVO'
        ");

        Schema::table('despachos', function (Blueprint $table) {
            $table->foreignId('anulado_por')->nullable()->after('estado')->constrained('users');
            $table->dateTime('anulado_at')->nullable()->after('anulado_por');
            $table->text('motivo_anulacion')->nullable()->after('anulado_at');
        });
    }

    public function down(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropColumn(['anulado_at', 'motivo_anulacion']);
        });

        DB::statement("
            ALTER TABLE lotes MODIFY estado ENUM(
                'ACTIVO', 'PROXIMO_A_VENCER', 'VENCIDO', 'AGOTADO'
            ) NOT NULL DEFAULT 'ACTIVO'
        ");

        DB::statement("
            ALTER TABLE movimientos_inventario MODIFY tipo_movimiento ENUM(
                'ENTRADA_COMPRA', 'CONSUMO_PRODUCCION', 'INGRESO_PRODUCCION', 'SALIDA_VENTA',
                'MERMA_DESECHO', 'BAJA_VENCIMIENTO'
            ) NOT NULL
        ");
    }
};
