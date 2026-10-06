<?php

namespace Tests\Feature\Api;

use App\Models\Cliente;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Services\DespachoService;
use App\Services\IngresoInsumoService;
use App\Services\MermaService;
use App\Services\ProduccionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Escenario con los 5 tipos de movimiento que genera la operación:
 *   1 ENTRADA_COMPRA      (20 kg de harina)
 *   4 CONSUMO_PRODUCCION  (harina, leche B, leche A, azúcar)
 *   1 INGRESO_PRODUCCION  (250 panes)
 *   1 SALIDA_VENTA        (100 panes)
 *   1 MERMA_DESECHO       (5 panes)
 */
class MovimientoInventarioApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private array $escenario;
    private int $lotePanId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->escenario = $this->crearEscenarioProduccion();
        $responsable = $this->crearUsuario();
        $proveedor = Proveedor::create(['codigo_proveedor' => 'PRV-001', 'razon_social' => 'Molinos Andinos']);
        $cliente = Cliente::create(['codigo_cliente' => 'CLI-001', 'razon_social' => 'Supermercado El Prado']);

        app(IngresoInsumoService::class)->registrarEntradaInsumo([
            'insumo_id' => $this->escenario['harina']->id,
            'proveedor_id' => $proveedor->id,
            'cantidad' => 20,
            'fecha_vencimiento' => now()->addDays(120)->toDateString(),
        ], $responsable->id);

        $orden = app(ProduccionService::class)->ejecutarOrdenProduccion($this->escenario['pan']->id, 250, $responsable->id);
        $this->lotePanId = $orden->lote_producto_id;

        app(DespachoService::class)->registrarDespacho(
            $cliente->id,
            [['producto_id' => $this->escenario['pan']->id, 'cantidad' => 100]],
            $responsable->id,
        );

        app(MermaService::class)->registrarMerma($this->lotePanId, 5, 'DANO_EMPAQUE', $responsable->id);
    }

    public function test_lista_el_kardex_paginado_del_mas_reciente_al_mas_antiguo(): void
    {
        $this->autenticarComo();

        $response = $this->getJson('/api/v1/movimientos')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 8)
            ->assertJsonCount(8, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'tipo_movimiento', 'es_entrada', 'cantidad', 'fecha', 'lote' => ['codigo_lote'], 'responsable']],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ])
            ->assertJsonMissingPath('resumen');

        $this->assertSame(MovimientoInventario::MERMA_DESECHO, $response->json('data.0.tipo_movimiento'));
        $this->assertSame(MovimientoInventario::ENTRADA_COMPRA, $response->json('data.7.tipo_movimiento'));
    }

    public function test_filtra_por_tipo_de_movimiento(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/movimientos?tipo_movimiento=CONSUMO_PRODUCCION')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);

        $this->getJson('/api/v1/movimientos?tipo_movimiento=SALIDA_VENTA')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.cantidad', '100.000');
    }

    public function test_filtra_por_lote_e_incluye_resumen(): void
    {
        $this->autenticarComo();

        $this->getJson("/api/v1/movimientos?lote_id={$this->escenario['loteLecheB']->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo_movimiento', MovimientoInventario::CONSUMO_PRODUCCION)
            ->assertJsonPath('data.0.cantidad', '3.000')
            ->assertJsonPath('resumen.total_entradas', '0.000')
            ->assertJsonPath('resumen.total_salidas', '3.000');
    }

    public function test_filtra_por_item_y_calcula_saldo_neto(): void
    {
        $this->autenticarComo();
        $panId = $this->escenario['pan']->id;

        // Pan: +250 producción, -100 venta, -5 merma
        $this->getJson("/api/v1/movimientos?tipo_item=PRODUCTO&item_id={$panId}")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('resumen.movimientos', 3)
            ->assertJsonPath('resumen.total_entradas', '250.000')
            ->assertJsonPath('resumen.total_salidas', '105.000')
            ->assertJsonPath('resumen.saldo_neto', '145.000');

        // Harina: +20 compra, -12.5 producción
        $this->getJson("/api/v1/movimientos?tipo_item=INSUMO&item_id={$this->escenario['harina']->id}")
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('resumen.saldo_neto', '7.500');
    }

    public function test_filtra_por_tipo_de_item_sin_item_especifico(): void
    {
        $this->autenticarComo();

        // Insumos: 1 compra + 4 consumos
        $this->getJson('/api/v1/movimientos?tipo_item=INSUMO')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonMissingPath('resumen');
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->autenticarComo();
        $hoy = now()->toDateString();
        $ayer = now()->subDay()->toDateString();
        $manana = now()->addDay()->toDateString();

        $this->getJson("/api/v1/movimientos?fecha_inicio={$hoy}&fecha_fin={$hoy}")->assertJsonPath('meta.total', 8);
        $this->getJson("/api/v1/movimientos?fecha_inicio={$manana}")->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/movimientos?fecha_fin={$ayer}")->assertJsonPath('meta.total', 0);
    }

    public function test_valida_los_filtros(): void
    {
        $this->autenticarComo();
        $hoy = now()->toDateString();
        $ayer = now()->subDay()->toDateString();

        $this->getJson('/api/v1/movimientos?tipo_movimiento=DESPACHO_CLIENTE&item_id=1&fecha_inicio=02-10-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo_movimiento', 'tipo_item', 'fecha_inicio']);

        $this->getJson("/api/v1/movimientos?fecha_inicio={$hoy}&fecha_fin={$ayer}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fecha_fin']);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/movimientos')->assertUnauthorized();
    }
}
