<?php

namespace Tests\Feature\Api;

use App\Models\Alerta;
use App\Models\Cliente;
use App\Models\Despacho;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\DespachoService;
use App\Services\IngresoInsumoService;
use App\Services\ProduccionService;
use App\Services\TrazabilidadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistorialYAnulacionApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private array $escenario;
    private User $operador;
    private Cliente $prado;
    private Cliente $rosa;
    private Proveedor $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->escenario = $this->crearEscenarioProduccion();
        $this->operador = $this->crearUsuario();
        $this->prado = Cliente::create(['codigo_cliente' => 'CLI-001', 'razon_social' => 'Supermercado El Prado']);
        $this->rosa = Cliente::create(['codigo_cliente' => 'CLI-002', 'razon_social' => 'Tienda Doña Rosa']);
        $this->proveedor = Proveedor::create(['codigo_proveedor' => 'PRV-001', 'razon_social' => 'Lácteos del Valle']);
    }

    // =====================================================================
    //  Historial de despachos y órdenes
    // =====================================================================

    public function test_lista_el_historial_de_despachos_con_filtros(): void
    {
        $this->producir(250);
        $this->despachar($this->prado, 100);
        $this->despachar($this->rosa, 50);

        $this->autenticarComo();

        $this->getJson('/api/v1/despachos')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.cliente.razon_social', 'Tienda Doña Rosa') // más reciente primero
            ->assertJsonPath('data.0.total', '40.000')
            ->assertJsonStructure(['data' => [['codigo_despacho', 'estado', 'fecha_despacho', 'cliente', 'responsable', 'detalles']]]);

        $this->getJson("/api/v1/despachos?cliente_id={$this->prado->id}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.detalles.0.cantidad', '100.000');

        $this->getJson('/api/v1/despachos?estado=ANULADO')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/despachos?fecha_inicio=' . now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
    }

    public function test_lista_el_historial_de_ordenes_de_produccion(): void
    {
        $orden = $this->producir(250);
        $this->producir(100);

        $this->autenticarComo();

        $this->getJson('/api/v1/ordenes-produccion')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(4, 'data.1.consumos');

        $this->getJson("/api/v1/ordenes-produccion?buscar={$orden->codigo_orden}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lote_producto.cantidad_inicial', '250.000');

        $this->getJson("/api/v1/ordenes-produccion/{$orden->id}")
            ->assertOk()
            ->assertJsonPath('data.codigo_orden', $orden->codigo_orden);
    }

    // =====================================================================
    //  Anulación de despachos
    // =====================================================================

    public function test_anular_un_despacho_devuelve_el_producto_a_sus_lotes(): void
    {
        $orden = $this->producir(250);
        $despacho = $this->despachar($this->prado, 250); // agota el lote
        $lotePan = Lote::find($orden->lote_producto_id);
        $this->assertSame(Lote::ESTADO_AGOTADO, $lotePan->estado);

        $admin = $this->autenticarComo(User::ROLE_ADMIN);

        $this->postJson("/api/v1/despachos/{$despacho->id}/anular", ['motivo' => 'Registrado al cliente equivocado.'])
            ->assertOk()
            ->assertJsonPath('data.estado', Despacho::ESTADO_ANULADO)
            ->assertJsonPath('data.anulacion.motivo', 'Registrado al cliente equivocado.')
            ->assertJsonPath('data.anulacion.responsable', $admin->name);

        // El saldo volvió al mismo lote, que deja de estar agotado
        $lotePan->refresh();
        $this->assertSame('250.000', $lotePan->cantidad_actual);
        $this->assertSame(Lote::ESTADO_ACTIVO, $lotePan->estado);
        $this->assertSame('250.000', $this->escenario['pan']->fresh()->stock_actual);

        // Kardex: el SALIDA_VENTA original sigue intacto y se agrega el compensatorio
        $this->assertDatabaseHas('movimientos_inventario', ['lote_id' => $lotePan->id, 'tipo_movimiento' => MovimientoInventario::SALIDA_VENTA, 'cantidad' => '250.000']);
        $this->assertDatabaseHas('movimientos_inventario', [
            'lote_id' => $lotePan->id,
            'tipo_movimiento' => MovimientoInventario::DEVOLUCION_CLIENTE,
            'cantidad' => '250.000',
            'user_id' => $admin->id,
        ]);

        // La trazabilidad ya no cuenta a ese cliente
        $reporte = app(TrazabilidadService::class)->rastrearInsumoHaciaAdelante($this->escenario['loteLecheB']->id);
        $this->assertSame(0, $reporte['resumen']['clientes_afectados']);
    }

    public function test_un_despacho_no_puede_anularse_dos_veces_ni_fuera_de_plazo(): void
    {
        $this->producir(250);
        $despacho = $this->despachar($this->prado, 100);
        $otro = $this->despachar($this->rosa, 50);

        $this->autenticarComo(User::ROLE_ADMIN);
        $this->postJson("/api/v1/despachos/{$despacho->id}/anular", ['motivo' => 'Error de digitación en la cantidad.'])->assertOk();
        $this->postJson("/api/v1/despachos/{$despacho->id}/anular", ['motivo' => 'Error de digitación en la cantidad.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "El despacho {$despacho->codigo_despacho} ya está anulado.");

        $this->travel(25)->hours();
        $this->postJson("/api/v1/despachos/{$otro->id}/anular", ['motivo' => 'Se intenta anular al día siguiente.'])
            ->assertUnprocessable();
        $this->assertSame(Despacho::ESTADO_COMPLETADO, $otro->fresh()->estado);
    }

    public function test_anular_exige_motivo_y_rol_de_administrador(): void
    {
        $this->producir(250);
        $despacho = $this->despachar($this->prado, 100);

        $this->autenticarComo(User::ROLE_OPERADOR);
        $this->postJson("/api/v1/despachos/{$despacho->id}/anular", ['motivo' => 'Quiero anularlo yo mismo.'])->assertForbidden();

        $this->autenticarComo(User::ROLE_ADMIN);
        $this->postJson("/api/v1/despachos/{$despacho->id}/anular", ['motivo' => 'corto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['motivo']);
    }

    // =====================================================================
    //  Anulación de ingresos de compra
    // =====================================================================

    public function test_anular_un_ingreso_de_compra_sin_uso(): void
    {
        $movimiento = $this->comprarLeche(30);
        $lote = $movimiento->lote;
        Alerta::create(['lote_id' => $lote->id, 'insumo_id' => $lote->insumo_id, 'tipo_alerta' => Alerta::PREVENTIVA_VENCIMIENTO, 'nivel_prioridad' => 'MEDIA', 'mensaje' => 'x']);
        $stockAntes = (float) $this->escenario['leche']->fresh()->stock_actual;

        $this->autenticarComo(User::ROLE_ADMIN);

        $this->postJson("/api/v1/lotes/{$lote->id}/anular", ['motivo' => 'Se cargó la factura de otro proveedor.'])
            ->assertOk()
            ->assertJsonPath('data.estado', Lote::ESTADO_ANULADO)
            ->assertJsonPath('data.cantidad_actual', '0.000');

        $this->assertEqualsWithDelta($stockAntes - 30, (float) $this->escenario['leche']->fresh()->stock_actual, 0.0001);
        $this->assertDatabaseHas('movimientos_inventario', [
            'lote_id' => $lote->id,
            'tipo_movimiento' => MovimientoInventario::ANULACION_COMPRA,
            'cantidad' => '30.000',
        ]);
        $this->assertTrue(Alerta::where('lote_id', $lote->id)->first()->leida);

        // Un lote anulado nunca se consume
        $this->postJson("/api/v1/lotes/{$lote->id}/anular", ['motivo' => 'Segundo intento de anulación.'])->assertUnprocessable();
    }

    public function test_no_se_anula_un_ingreso_que_ya_se_consumio(): void
    {
        // La leche comprada vence en 5 días, antes que LEC-B y LEC-A: FEFO la consume primero
        $movimiento = $this->comprarLeche(30, diasVida: 5);
        $this->producir(500); // 10 lt de leche, todos del lote comprado

        $this->autenticarComo(User::ROLE_ADMIN);

        $this->postJson("/api/v1/lotes/{$movimiento->lote_id}/anular", ['motivo' => 'Intento de anular un lote usado.'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');

        $this->assertNotSame(Lote::ESTADO_ANULADO, $movimiento->lote->fresh()->estado);
    }

    public function test_solo_se_anulan_lotes_de_compra(): void
    {
        $orden = $this->producir(100);

        $this->autenticarComo(User::ROLE_ADMIN);

        $this->postJson("/api/v1/lotes/{$orden->lote_producto_id}/anular", ['motivo' => 'Intento sobre un lote de producto.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Solo pueden anularse ingresos de compra (lotes de insumo).');
    }

    public function test_el_kardex_acepta_los_nuevos_tipos_de_movimiento(): void
    {
        $this->producir(250);
        $despacho = $this->despachar($this->prado, 100);

        $this->autenticarComo(User::ROLE_ADMIN);
        $this->postJson("/api/v1/despachos/{$despacho->id}/anular", ['motivo' => 'Prueba de movimiento compensatorio.'])->assertOk();

        $this->getJson('/api/v1/movimientos?tipo_movimiento=DEVOLUCION_CLIENTE')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.es_entrada', true);
    }

    // ---------- Helpers ----------

    private function producir(float $cantidad)
    {
        return app(ProduccionService::class)->ejecutarOrdenProduccion($this->escenario['pan']->id, $cantidad, $this->operador->id);
    }

    private function despachar(Cliente $cliente, float $cantidad): Despacho
    {
        return app(DespachoService::class)->registrarDespacho(
            $cliente->id,
            [['producto_id' => $this->escenario['pan']->id, 'cantidad' => $cantidad, 'precio_unitario' => 0.8]],
            $this->operador->id,
        );
    }

    private function comprarLeche(float $cantidad, int $diasVida = 15): MovimientoInventario
    {
        return app(IngresoInsumoService::class)->registrarEntradaInsumo([
            'insumo_id' => $this->escenario['leche']->id,
            'proveedor_id' => $this->proveedor->id,
            'cantidad' => $cantidad,
            'fecha_vencimiento' => now()->addDays($diasVida)->toDateString(),
        ], $this->operador->id);
    }
}
