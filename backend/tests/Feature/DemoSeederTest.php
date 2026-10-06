<?php

namespace Tests\Feature;

use App\Models\Despacho;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\Receta;
use App\Services\TrazabilidadService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Valida que la simulación de demostración deja datos coherentes:
 * si el seeder tuviera un error de lógica, la defensa en vivo mostraría
 * cifras que no cuadran.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_demostracion_genera_una_operacion_completa_y_coherente(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertNull(Carbon::getTestNow(), 'El seeder debe restaurar el reloj real.');

        // Volumen de operación
        $this->assertGreaterThan(15, OrdenProduccion::count());
        $this->assertGreaterThan(15, Despacho::where('estado', Despacho::ESTADO_COMPLETADO)->count());
        $this->assertSame(1, Despacho::where('estado', Despacho::ESTADO_ANULADO)->count());

        foreach ([MovimientoInventario::ENTRADA_COMPRA, MovimientoInventario::CONSUMO_PRODUCCION, MovimientoInventario::INGRESO_PRODUCCION,
            MovimientoInventario::SALIDA_VENTA, MovimientoInventario::MERMA_DESECHO, MovimientoInventario::BAJA_VENCIMIENTO,
            MovimientoInventario::DEVOLUCION_CLIENTE] as $tipo) {
            $this->assertTrue(MovimientoInventario::where('tipo_movimiento', $tipo)->exists(), "Falta un movimiento {$tipo}");
        }

        // Versionado de recetas: el pan tiene 2 versiones y solo una activa
        $pan = Producto::where('codigo', 'PRD-PAN')->firstOrFail();
        $this->assertSame(2, Receta::where('producto_id', $pan->id)->count());
        $this->assertSame(1, Receta::where('producto_id', $pan->id)->where('activa', true)->count());

        // Invariante del Kardex: para cada lote, entradas - salidas = saldo actual
        $entradas = "'" . implode("','", MovimientoInventario::TIPOS_ENTRADA) . "'";
        $descuadres = DB::table('lotes')
            ->join('movimientos_inventario as m', 'm.lote_id', '=', 'lotes.id')
            ->groupBy('lotes.id', 'lotes.codigo_lote', 'lotes.cantidad_actual')
            ->selectRaw("lotes.codigo_lote, lotes.cantidad_actual, SUM(CASE WHEN m.tipo_movimiento IN ({$entradas}) THEN m.cantidad ELSE -m.cantidad END) AS saldo_kardex")
            ->havingRaw("ABS(SUM(CASE WHEN m.tipo_movimiento IN ({$entradas}) THEN m.cantidad ELSE -m.cantidad END) - lotes.cantidad_actual) > 0.0005")
            ->get();
        $this->assertCount(0, $descuadres, 'Lotes cuyo saldo no cuadra con el Kardex: ' . $descuadres->pluck('codigo_lote')->implode(', '));

        // El stock de cada ítem coincide con la suma de sus lotes vigentes
        foreach (Insumo::all() as $insumo) {
            $suma = (float) Lote::where('tipo_item', Lote::TIPO_INSUMO)->where('insumo_id', $insumo->id)->disponibles()->sum('cantidad_actual');
            $this->assertEqualsWithDelta($suma, (float) $insumo->stock_actual, 0.0005, "Stock descuadrado: {$insumo->nombre}");
        }

        // Caso de trazabilidad preparado para la defensa
        $lote = Lote::where('codigo_lote', 'LEC-LV-2609')->firstOrFail();
        $reporte = app(TrazabilidadService::class)->rastrearInsumoHaciaAdelante($lote->id);
        $this->assertGreaterThanOrEqual(2, $reporte['resumen']['ordenes_produccion']);
        $this->assertGreaterThanOrEqual(2, $reporte['resumen']['clientes_afectados']);
    }

    public function test_no_se_ejecuta_sobre_una_base_con_datos(): void
    {
        Insumo::create(['codigo' => 'INS-X', 'nombre' => 'Existente', 'unidad_medida' => 'kg']);

        $this->seed(DemoSeeder::class);

        $this->assertSame(1, Insumo::count());
    }
}
