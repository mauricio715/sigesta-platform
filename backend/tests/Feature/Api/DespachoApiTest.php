<?php

namespace Tests\Feature\Api;

use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DespachoApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private Cliente $cliente;
    private Producto $pan;
    private Lote $loteA; // fabricado antes, vence en 5 días
    private Lote $loteB; // vence en 3 días

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = Cliente::create([
            'codigo_cliente' => 'CLI-001',
            'razon_social' => 'Supermercado El Prado',
            'nit_ci' => '1023456789',
        ]);

        $this->pan = Producto::create([
            'codigo' => 'PRD-PAN',
            'nombre' => 'Pan de leche',
            'unidad_medida' => 'unidad',
            'dias_vida_util' => 5,
        ]);

        $this->loteA = $this->crearLoteProducto($this->pan, 'PAN-A', 5, 100);
        $this->loteB = $this->crearLoteProducto($this->pan, 'PAN-B', 3, 60);
    }

    public function test_registra_un_despacho_descontando_por_fefo_via_api(): void
    {
        $operador = $this->autenticarComo();

        $response = $this->postJson('/api/v1/despachos', [
            'cliente_id' => $this->cliente->id,
            'items' => [
                ['producto_id' => $this->pan->id, 'cantidad' => 80, 'precio_unitario' => 0.8],
            ],
            'observaciones' => 'Entrega matutina',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.estado', 'COMPLETADO')
            ->assertJsonPath('data.cliente.razon_social', 'Supermercado El Prado')
            ->assertJsonPath('data.responsable.id', $operador->id)
            ->assertJsonPath('data.observaciones', 'Entrega matutina')
            ->assertJsonCount(2, 'data.detalles')
            // FEFO: primero B (vence antes), luego A
            ->assertJsonPath('data.detalles.0.lote.codigo_lote', 'PAN-B')
            ->assertJsonPath('data.detalles.0.cantidad', '60.000')
            ->assertJsonPath('data.detalles.0.subtotal', '48.000')
            ->assertJsonPath('data.detalles.1.lote.codigo_lote', 'PAN-A')
            ->assertJsonPath('data.detalles.1.cantidad', '20.000');

        $this->assertMatchesRegularExpression('/^DSP-\d{8}-\d{5}$/', $response->json('data.codigo_despacho'));

        $this->assertSame('0.000', $this->loteB->fresh()->cantidad_actual);
        $this->assertSame(Lote::ESTADO_AGOTADO, $this->loteB->fresh()->estado);
        $this->assertSame('80.000', $this->loteA->fresh()->cantidad_actual);
        $this->assertSame('80.000', $this->pan->fresh()->stock_actual);
    }

    public function test_stock_insuficiente_devuelve_422_y_no_registra_nada(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/despachos', [
            'cliente_id' => $this->cliente->id,
            'items' => [['producto_id' => $this->pan->id, 'cantidad' => 500]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'STOCK_INSUFICIENTE')
            ->assertJsonPath('data.tipo_item', 'PRODUCTO');

        $this->assertDatabaseCount('despachos', 0);
        $this->assertSame('60.000', $this->loteB->fresh()->cantidad_actual);
    }

    public function test_valida_cliente_items_y_campos_no_permitidos(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/despachos', [
            'cliente_id' => 99999,
            'items' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['cliente_id', 'items']);

        $this->postJson('/api/v1/despachos', [
            'cliente_id' => $this->cliente->id,
            'items' => [
                ['producto_id' => $this->pan->id, 'cantidad' => 0],
                ['producto_id' => $this->pan->id, 'cantidad' => 5, 'lote_id' => $this->loteA->id],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.cantidad', 'items.1']);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/despachos', [
            'cliente_id' => $this->cliente->id,
            'items' => [['producto_id' => $this->pan->id, 'cantidad' => 10]],
        ])->assertUnauthorized();
    }
}
