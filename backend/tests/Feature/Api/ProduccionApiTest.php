<?php

namespace Tests\Feature\Api;

use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProduccionApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private array $escenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->escenario = $this->crearEscenarioProduccion();
    }

    public function test_ejecuta_una_orden_de_produccion_via_api(): void
    {
        $operador = $this->autenticarComo();

        $response = $this->postJson('/api/v1/produccion', [
            'producto_id' => $this->escenario['pan']->id,
            'cantidad' => 250,
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.estado', 'COMPLETADA')
            ->assertJsonPath('data.cantidad_producida', '250.000')
            ->assertJsonPath('data.producto.nombre', 'Pan de leche')
            ->assertJsonPath('data.lote_producto.tipo_item', 'PRODUCTO')
            ->assertJsonPath('data.lote_producto.cantidad_inicial', '250.000')
            ->assertJsonPath('data.lote_producto.fecha_vencimiento', now()->addDays(5)->toDateString())
            ->assertJsonPath('data.responsable.id', $operador->id)
            ->assertJsonCount(4, 'data.consumos');

        $this->assertMatchesRegularExpression('/^OP-\d{8}-\d{5}$/', $response->json('data.codigo_orden'));

        // FEFO en la leche: 3 lt de B (vence antes) y 2 lt de A
        $consumosLeche = collect($response->json('data.consumos'))
            ->where('insumo.codigo', 'INS-LEC')
            ->mapWithKeys(fn ($c) => [$c['lote']['codigo_lote'] => $c['cantidad_consumida']])
            ->all();
        $this->assertSame(['LEC-B' => '3.000', 'LEC-A' => '2.000'], $consumosLeche);

        $this->assertSame('0.000', $this->escenario['loteLecheB']->fresh()->cantidad_actual);
        $this->assertSame('250.000', $this->escenario['pan']->fresh()->stock_actual);
    }

    public function test_acepta_un_codigo_de_lote_personalizado(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/produccion', [
            'producto_id' => $this->escenario['pan']->id,
            'cantidad' => 100,
            'codigo_lote' => 'PAN-ESPECIAL-01',
        ])->assertCreated()->assertJsonPath('data.lote_producto.codigo_lote', 'PAN-ESPECIAL-01');
    }

    public function test_codigo_de_lote_duplicado_devuelve_422(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/produccion', [
            'producto_id' => $this->escenario['pan']->id,
            'cantidad' => 100,
            'codigo_lote' => 'HAR-001',
        ])->assertUnprocessable()->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('ordenes_produccion', 0);
    }

    public function test_stock_insuficiente_devuelve_422_sin_dejar_rastros(): void
    {
        $this->autenticarComo();

        // 5000 panes requieren 250 kg de harina; solo hay 50
        $this->postJson('/api/v1/produccion', [
            'producto_id' => $this->escenario['pan']->id,
            'cantidad' => 5000,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error', 'STOCK_INSUFICIENTE')
            ->assertJsonPath('data.requerido', '250.000')
            ->assertJsonPath('data.disponible', '50.000');

        $this->assertDatabaseCount('ordenes_produccion', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_producto_sin_receta_activa_devuelve_422(): void
    {
        $this->autenticarComo();
        $queso = Producto::create(['codigo' => 'PRD-QUE', 'nombre' => 'Queso fresco', 'unidad_medida' => 'kg', 'dias_vida_util' => 15]);

        $this->postJson('/api/v1/produccion', ['producto_id' => $queso->id, 'cantidad' => 10])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'RECETA_NO_DISPONIBLE');
    }

    public function test_valida_la_entrada(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/produccion', [
            'producto_id' => 99999,
            'cantidad' => -5,
            'codigo_lote' => 'lote con espacios',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['producto_id', 'cantidad', 'codigo_lote']);

        $this->postJson('/api/v1/produccion', [
            'producto_id' => $this->escenario['pan']->id,
            'cantidad' => 10.12345,
        ])->assertJsonValidationErrors(['cantidad']);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/produccion', [
            'producto_id' => $this->escenario['pan']->id,
            'cantidad' => 100,
        ])->assertUnauthorized();
    }
}
