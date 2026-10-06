<?php

namespace Tests\Feature;

use App\Exceptions\RecetaNoDisponibleException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\OrdenProduccion;
use App\Models\ProduccionConsumo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\User;
use App\Services\LoteService;
use App\Services\ProduccionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProduccionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProduccionService $produccion;
    private LoteService $lotes;
    private User $operador;

    private Insumo $harina;
    private Insumo $leche;
    private Insumo $azucar;
    private Producto $pan;
    private Receta $receta;

    private Lote $loteHarina;
    private Lote $loteLecheA; // ingresó primero, vence en 20 días
    private Lote $loteLecheB; // ingresó después, vence en 10 días
    private Lote $loteAzucar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produccion = app(ProduccionService::class);
        $this->lotes = app(LoteService::class);

        $this->operador = User::create([
            'name' => 'Operador Test',
            'email' => 'operador.test@sigesta.com',
            'password' => 'password',
            'role' => User::ROLE_OPERADOR,
        ]);

        $this->harina = Insumo::create(['codigo' => 'INS-HAR-T', 'nombre' => 'Harina de trigo', 'unidad_medida' => 'kg', 'stock_minimo' => 10]);
        $this->leche = Insumo::create(['codigo' => 'INS-LEC-T', 'nombre' => 'Leche entera', 'unidad_medida' => 'lt', 'stock_minimo' => 5]);
        $this->azucar = Insumo::create(['codigo' => 'INS-AZU-T', 'nombre' => 'Azúcar blanca', 'unidad_medida' => 'kg', 'stock_minimo' => 2]);

        $this->pan = Producto::create([
            'codigo' => 'PRD-PAN-T',
            'nombre' => 'Pan de leche',
            'unidad_medida' => 'unidad',
            'dias_vida_util' => 5,
        ]);

        // BOM: 100 panes = 5 kg harina + 2 lt leche + 0.8 kg azúcar
        $this->receta = Receta::create([
            'producto_id' => $this->pan->id,
            'nombre_receta' => 'Pan de leche estándar',
            'rendimiento_base' => 100,
        ]);
        $this->receta->insumos()->attach([
            $this->harina->id => ['cantidad_requerida' => 5],
            $this->leche->id => ['cantidad_requerida' => 2],
            $this->azucar->id => ['cantidad_requerida' => 0.8],
        ]);

        $this->loteHarina = $this->crearLoteInsumo($this->harina, 'HAR-T-01', diasParaVencer: 90, cantidad: 50);
        $this->loteLecheA = $this->crearLoteInsumo($this->leche, 'LEC-T-A', diasParaVencer: 20, cantidad: 40, diasDesdeFabricacion: 3);
        $this->loteLecheB = $this->crearLoteInsumo($this->leche, 'LEC-T-B', diasParaVencer: 10, cantidad: 3, diasDesdeFabricacion: 1);
        $this->loteAzucar = $this->crearLoteInsumo($this->azucar, 'AZU-T-01', diasParaVencer: 180, cantidad: 10);
    }

    // =====================================================================
    //  Pruebas
    // =====================================================================

    public function test_ejecuta_orden_de_produccion_descontando_insumos_por_bom_y_fefo(): void
    {
        // 250 panes => factor 2.5 => harina 12.5 kg, leche 5 lt, azúcar 2 kg
        $orden = $this->produccion->ejecutarOrdenProduccion($this->pan->id, 250, $this->operador->id);

        // Orden
        $this->assertSame(OrdenProduccion::ESTADO_COMPLETADA, $orden->estado);
        $this->assertMatchesRegularExpression('/^OP-\d{8}-\d{5}$/', $orden->codigo_orden);
        $this->assertSame('250.000', $orden->cantidad_producida);
        $this->assertSame($this->receta->id, $orden->receta_id);

        // Descuento por BOM
        $this->assertSame('37.500', $this->loteHarina->fresh()->cantidad_actual);
        $this->assertSame('8.000', $this->loteAzucar->fresh()->cantidad_actual);

        // FEFO en leche: B (vence antes) se agota con 3 lt; los 2 lt restantes salen de A
        $this->assertSame('0.000', $this->loteLecheB->fresh()->cantidad_actual);
        $this->assertSame(Lote::ESTADO_AGOTADO, $this->loteLecheB->fresh()->estado);
        $this->assertSame('38.000', $this->loteLecheA->fresh()->cantidad_actual);

        // Lote de producto terminado
        $loteProducto = $orden->loteProducto;
        $this->assertNotNull($loteProducto);
        $this->assertSame(Lote::TIPO_PRODUCTO, $loteProducto->tipo_item);
        $this->assertSame($this->pan->id, $loteProducto->producto_id);
        $this->assertSame('250.000', $loteProducto->cantidad_inicial);
        $this->assertSame(now()->toDateString(), $loteProducto->fecha_fabricacion->toDateString());
        $this->assertSame(now()->addDays(5)->toDateString(), $loteProducto->fecha_vencimiento->toDateString());

        // Stock del producto y Kardex: 4 consumos (harina, leche B, leche A, azúcar) + 1 ingreso
        $this->assertSame('250.000', $this->pan->fresh()->stock_actual);
        $this->assertSame(4, MovimientoInventario::where('tipo_movimiento', MovimientoInventario::CONSUMO_PRODUCCION)->count());
        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo_movimiento' => MovimientoInventario::INGRESO_PRODUCCION,
            'lote_id' => $loteProducto->id,
            'cantidad' => '250.000',
            'user_id' => $this->operador->id,
        ]);
        $this->assertStringContainsString(
            $orden->codigo_orden,
            MovimientoInventario::where('lote_id', $this->loteHarina->id)->first()->motivo_observacion,
        );
    }

    public function test_revertir_transaccion_si_falta_stock_de_algun_insumo(): void
    {
        // Dejamos el azúcar (último insumo del BOM) en 1 kg; la orden necesita 2 kg
        $this->lotes->descontarStockInsumo($this->azucar->id, 9, $this->operador->id, 'Preparación de la prueba');
        $movimientosPrevios = MovimientoInventario::count();

        try {
            $this->produccion->ejecutarOrdenProduccion($this->pan->id, 250, $this->operador->id);
            $this->fail('Se esperaba StockInsuficienteException.');
        } catch (StockInsuficienteException $e) {
            $this->assertStringContainsString('Azúcar blanca', $e->getMessage());
        }

        // Rollback completo: ni orden, ni lote de producto, ni trazabilidad
        $this->assertDatabaseCount('ordenes_produccion', 0);
        $this->assertDatabaseCount('produccion_consumos', 0);
        $this->assertSame(0, Lote::where('tipo_item', Lote::TIPO_PRODUCTO)->count());

        // Harina y leche se descontaron antes del fallo: deben quedar intactas
        $this->assertSame('50.000', $this->loteHarina->fresh()->cantidad_actual);
        $this->assertSame('40.000', $this->loteLecheA->fresh()->cantidad_actual);
        $this->assertSame('3.000', $this->loteLecheB->fresh()->cantidad_actual);
        $this->assertSame('50.000', $this->harina->fresh()->stock_actual);

        $this->assertSame($movimientosPrevios, MovimientoInventario::count());
        $this->assertSame('0.000', $this->pan->fresh()->stock_actual);
    }

    public function test_registra_trazabilidad_bidireccional_en_produccion_consumos(): void
    {
        $orden = $this->produccion->ejecutarOrdenProduccion($this->pan->id, 250, $this->operador->id);
        $loteProducto = $orden->loteProducto;

        // ---- Hacia atrás: desde el lote de pan, ¿qué lotes de insumo se usaron? ----
        $origen = $loteProducto->consumosOrigen()
            ->with('loteInsumo')
            ->get()
            ->mapWithKeys(fn (ProduccionConsumo $c) => [$c->loteInsumo->codigo_lote => $c->cantidad_consumida])
            ->all();

        $this->assertEquals([
            'HAR-T-01' => '12.500',
            'LEC-T-B' => '3.000',
            'LEC-T-A' => '2.000',
            'AZU-T-01' => '2.000',
        ], $origen);

        $this->assertSame($orden->id, $loteProducto->ordenProduccion->id);

        // ---- Hacia adelante: desde un lote de leche, ¿qué lotes de producto se fabricaron? ----
        $derivados = $this->loteLecheB->consumosEnProduccion()
            ->with('ordenProduccion.loteProducto')
            ->get()
            ->map(fn (ProduccionConsumo $c) => $c->ordenProduccion->loteProducto->id)
            ->all();

        $this->assertSame([$loteProducto->id], $derivados);

        // Un lote de insumo no usado no tiene derivados
        $loteSinUso = $this->crearLoteInsumo($this->leche, 'LEC-T-C', diasParaVencer: 30, cantidad: 10);
        $this->assertCount(0, $loteSinUso->consumosEnProduccion);
    }

    public function test_lanza_excepcion_si_el_producto_no_tiene_receta_activa(): void
    {
        $this->receta->update(['activa' => false]);

        $this->expectException(RecetaNoDisponibleException::class);
        $this->expectExceptionMessage('no tiene una receta activa');

        $this->produccion->ejecutarOrdenProduccion($this->pan->id, 100, $this->operador->id);
    }

    public function test_usa_el_codigo_de_lote_personalizado(): void
    {
        $orden = $this->produccion->ejecutarOrdenProduccion($this->pan->id, 100, $this->operador->id, '  PAN-ESPECIAL-001 ');

        $this->assertSame('PAN-ESPECIAL-001', $orden->loteProducto->codigo_lote);
    }

    public function test_rechaza_codigo_de_lote_duplicado_sin_consumir_insumos(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->produccion->ejecutarOrdenProduccion($this->pan->id, 100, $this->operador->id, 'HAR-T-01');
        } finally {
            $this->assertSame('50.000', $this->loteHarina->fresh()->cantidad_actual);
            $this->assertDatabaseCount('ordenes_produccion', 0);
        }
    }

    public function test_rechaza_cantidades_no_positivas(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->produccion->ejecutarOrdenProduccion($this->pan->id, 0, $this->operador->id);
    }

    // =====================================================================
    //  Helpers
    // =====================================================================

    private function crearLoteInsumo(
        Insumo $insumo,
        string $codigo,
        int $diasParaVencer,
        float $cantidad,
        int $diasDesdeFabricacion = 1,
    ): Lote {
        $lote = Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => Lote::TIPO_INSUMO,
            'insumo_id' => $insumo->id,
            'fecha_fabricacion' => now()->subDays($diasDesdeFabricacion)->toDateString(),
            'fecha_vencimiento' => now()->addDays($diasParaVencer)->toDateString(),
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => $diasParaVencer <= 15 ? Lote::ESTADO_PROXIMO_A_VENCER : Lote::ESTADO_ACTIVO,
        ]);

        $this->lotes->recalcularStockInsumo($insumo);

        return $lote;
    }
}
