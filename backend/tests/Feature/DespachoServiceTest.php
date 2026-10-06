<?php

namespace Tests\Feature;

use App\Exceptions\StockInsuficienteException;
use App\Models\Alerta;
use App\Models\Cliente;
use App\Models\Despacho;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\User;
use App\Services\DespachoService;
use App\Services\LoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DespachoServiceTest extends TestCase
{
    use RefreshDatabase;

    private DespachoService $despachos;
    private LoteService $lotes;
    private User $operador;
    private Cliente $cliente;
    private Producto $pan;
    private Producto $queso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->despachos = app(DespachoService::class);
        $this->lotes = app(LoteService::class);

        $this->operador = User::create([
            'name' => 'Operador Test',
            'email' => 'operador.test@sigesta.com',
            'password' => 'password',
            'role' => User::ROLE_OPERADOR,
        ]);

        $this->cliente = Cliente::create([
            'codigo_cliente' => 'CLI-001',
            'razon_social' => 'Supermercado El Prado',
            'nit_ci' => '1023456789',
        ]);

        $this->pan = Producto::create([
            'codigo' => 'PRD-PAN-T', 'nombre' => 'Pan de leche', 'unidad_medida' => 'unidad',
            'dias_vida_util' => 5, 'stock_minimo' => 100,
        ]);

        $this->queso = Producto::create([
            'codigo' => 'PRD-QUE-T', 'nombre' => 'Queso fresco', 'unidad_medida' => 'kg',
            'dias_vida_util' => 15,
        ]);
    }

    public function test_despacho_consume_producto_terminado_por_fefo(): void
    {
        // A se fabricó primero pero vence después; B vence antes
        $loteA = $this->crearLoteProducto($this->pan, 'PAN-A', diasParaVencer: 5, cantidad: 100);
        $loteB = $this->crearLoteProducto($this->pan, 'PAN-B', diasParaVencer: 3, cantidad: 60);

        $despacho = $this->despachos->registrarDespacho(
            $this->cliente->id,
            [['producto_id' => $this->pan->id, 'cantidad' => 80]],
            $this->operador->id,
        );

        $this->assertSame(Despacho::ESTADO_COMPLETADO, $despacho->estado);
        $this->assertMatchesRegularExpression('/^DSP-\d{8}-\d{5}$/', $despacho->codigo_despacho);

        // FEFO: B se agota (60) y A aporta los 20 restantes
        $this->assertSame('0.000', $loteB->fresh()->cantidad_actual);
        $this->assertSame(Lote::ESTADO_AGOTADO, $loteB->fresh()->estado);
        $this->assertSame('80.000', $loteA->fresh()->cantidad_actual);

        // Detalles con el lote exacto, en orden FEFO
        $this->assertCount(2, $despacho->detalles);
        $this->assertSame($loteB->id, $despacho->detalles[0]->lote_producto_id);
        $this->assertSame('60.000', $despacho->detalles[0]->cantidad);
        $this->assertSame($loteA->id, $despacho->detalles[1]->lote_producto_id);
        $this->assertSame('20.000', $despacho->detalles[1]->cantidad);

        // Kardex SALIDA_VENTA
        $this->assertSame(2, MovimientoInventario::where('tipo_movimiento', MovimientoInventario::SALIDA_VENTA)->count());
        $this->assertStringContainsString(
            $despacho->codigo_despacho,
            MovimientoInventario::where('lote_id', $loteB->id)->first()->motivo_observacion,
        );

        // Stock del producto y alerta de mínimo (80 < 100)
        $this->assertSame('80.000', $this->pan->fresh()->stock_actual);
        $this->assertDatabaseHas('alertas', [
            'producto_id' => $this->pan->id,
            'tipo_alerta' => Alerta::STOCK_MINIMO,
            'nivel_prioridad' => Alerta::PRIORIDAD_MEDIA,
        ]);
    }

    public function test_despacha_varios_productos_y_consolida_items_repetidos(): void
    {
        $lotePan = $this->crearLoteProducto($this->pan, 'PAN-01', diasParaVencer: 4, cantidad: 200);
        $loteQueso = $this->crearLoteProducto($this->queso, 'QUE-01', diasParaVencer: 12, cantidad: 30);

        $despacho = $this->despachos->registrarDespacho($this->cliente->id, [
            ['producto_id' => $this->pan->id, 'cantidad' => 10, 'precio_unitario' => 0.8],
            ['producto_id' => $this->queso->id, 'cantidad' => 5.5, 'precio_unitario' => 45],
            ['producto_id' => $this->pan->id, 'cantidad' => 15, 'precio_unitario' => 0.8],
        ], $this->operador->id, 'Pedido semanal');

        $this->assertCount(2, $despacho->detalles);
        $this->assertSame('175.000', $lotePan->fresh()->cantidad_actual);
        $this->assertSame('24.500', $loteQueso->fresh()->cantidad_actual);

        $this->assertDatabaseHas('despacho_detalles', [
            'despacho_id' => $despacho->id,
            'lote_producto_id' => $lotePan->id,
            'cantidad' => '25.000',
            'precio_unitario' => '0.800',
        ]);
        $this->assertSame('Pedido semanal', $despacho->observaciones);
    }

    public function test_revierte_el_despacho_completo_si_falta_stock_de_un_producto(): void
    {
        $lotePan = $this->crearLoteProducto($this->pan, 'PAN-01', diasParaVencer: 4, cantidad: 200);
        $this->crearLoteProducto($this->queso, 'QUE-01', diasParaVencer: 12, cantidad: 3);

        try {
            $this->despachos->registrarDespacho($this->cliente->id, [
                ['producto_id' => $this->pan->id, 'cantidad' => 50],
                ['producto_id' => $this->queso->id, 'cantidad' => 10],
            ], $this->operador->id);
            $this->fail('Se esperaba StockInsuficienteException.');
        } catch (StockInsuficienteException $e) {
            $this->assertStringContainsString('producto "Queso fresco"', $e->getMessage());
        }

        $this->assertDatabaseCount('despachos', 0);
        $this->assertDatabaseCount('despacho_detalles', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertSame('200.000', $lotePan->fresh()->cantidad_actual);
    }

    public function test_no_despacha_lotes_vencidos_aunque_su_estado_no_se_haya_actualizado(): void
    {
        $vencido = $this->crearLoteProducto($this->pan, 'PAN-VENC', diasParaVencer: -1, cantidad: 500, diasDesdeFabricacion: 6);
        $vigente = $this->crearLoteProducto($this->pan, 'PAN-VIG', diasParaVencer: 4, cantidad: 40);

        $this->despachos->registrarDespacho(
            $this->cliente->id,
            [['producto_id' => $this->pan->id, 'cantidad' => 30]],
            $this->operador->id,
        );

        $this->assertSame('500.000', $vencido->fresh()->cantidad_actual);
        $this->assertSame('10.000', $vigente->fresh()->cantidad_actual);
    }

    public function test_rechaza_despachos_sin_items_o_con_cantidades_invalidas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->despachos->registrarDespacho($this->cliente->id, [], $this->operador->id);
    }

    public function test_rechaza_items_con_cantidad_cero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ítem #1');

        $this->despachos->registrarDespacho(
            $this->cliente->id,
            [['producto_id' => $this->pan->id, 'cantidad' => 0]],
            $this->operador->id,
        );
    }

    // ---------- Helpers ----------

    private function crearLoteProducto(
        Producto $producto,
        string $codigo,
        int $diasParaVencer,
        float $cantidad,
        int $diasDesdeFabricacion = 1,
    ): Lote {
        $lote = Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => Lote::TIPO_PRODUCTO,
            'producto_id' => $producto->id,
            'fecha_fabricacion' => now()->subDays($diasDesdeFabricacion)->toDateString(),
            'fecha_vencimiento' => now()->addDays($diasParaVencer)->toDateString(),
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => Lote::ESTADO_ACTIVO,
        ]);

        $this->lotes->recalcularStockProducto($producto);

        return $lote;
    }
}
