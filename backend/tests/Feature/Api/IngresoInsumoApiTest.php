<?php

namespace Tests\Feature\Api;

use App\Models\Alerta;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngresoInsumoApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private Insumo $harina;
    private Proveedor $molino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harina = Insumo::create([
            'codigo' => 'INS-HAR',
            'nombre' => 'Harina de trigo',
            'unidad_medida' => 'kg',
            'stock_minimo' => 20,
        ]);

        $this->molino = Proveedor::create([
            'codigo_proveedor' => 'PRV-001',
            'razon_social' => 'Molinos Andinos S.R.L.',
            'nit' => '3001001',
        ]);
    }

    public function test_registra_una_compra_creando_lote_y_movimiento_en_el_kardex(): void
    {
        $operador = $this->autenticarComo(User::ROLE_OPERADOR);

        $response = $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra([
            'codigo_lote_proveedor' => 'MA-7781',
            'documento_referencia' => 'FAC-001234',
        ]));

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.tipo_movimiento', MovimientoInventario::ENTRADA_COMPRA)
            ->assertJsonPath('data.es_entrada', true)
            ->assertJsonPath('data.cantidad', '50.000')
            ->assertJsonPath('data.responsable.id', $operador->id)
            ->assertJsonPath('data.lote.tipo_item', Lote::TIPO_INSUMO)
            ->assertJsonPath('data.lote.estado', Lote::ESTADO_ACTIVO)
            ->assertJsonPath('data.lote.codigo_lote_proveedor', 'MA-7781')
            ->assertJsonPath('data.lote.cantidad_inicial', '50.000')
            ->assertJsonPath('data.lote.proveedor.razon_social', 'Molinos Andinos S.R.L.')
            ->assertJsonPath('data.lote.insumo.stock_actual', '50.000');

        // Código interno autogenerado: <código insumo>-<fecha>-<id>
        $this->assertMatchesRegularExpression('/^INS-HAR-\d{8}-\d{5}$/', $response->json('data.lote.codigo_lote'));

        $movimiento = MovimientoInventario::first();
        $this->assertStringContainsString('Molinos Andinos', $movimiento->motivo_observacion);
        $this->assertStringContainsString('FAC-001234', $movimiento->motivo_observacion);
        $this->assertSame('50.000', $this->harina->fresh()->stock_actual);
    }

    public function test_acepta_un_codigo_de_lote_interno_personalizado(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra(['codigo_lote' => 'HAR-2026-001']))
            ->assertCreated()
            ->assertJsonPath('data.lote.codigo_lote', 'HAR-2026-001');

        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra(['codigo_lote' => 'HAR-2026-001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo_lote']);
    }

    public function test_compras_sucesivas_acumulan_stock_y_resuelven_la_alerta_de_minimo(): void
    {
        $this->autenticarComo();

        Alerta::create([
            'insumo_id' => $this->harina->id,
            'tipo_alerta' => Alerta::STOCK_MINIMO,
            'nivel_prioridad' => Alerta::PRIORIDAD_ALTA,
            'mensaje' => 'Stock de "Harina de trigo" por debajo del mínimo.',
        ]);

        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra(['cantidad' => 10]))->assertCreated();
        $this->assertFalse(Alerta::first()->leida, '10 kg sigue bajo el mínimo de 20 kg');

        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra(['cantidad' => 15.5]))->assertCreated();

        $this->assertSame('25.500', $this->harina->fresh()->stock_actual);
        $this->assertTrue(Alerta::first()->leida, 'Con 25.5 kg el mínimo quedó cubierto');
        $this->assertSame(2, Lote::where('insumo_id', $this->harina->id)->count());
    }

    public function test_un_lote_que_llega_dentro_de_su_ventana_preventiva_se_alerta_de_inmediato(): void
    {
        $this->autenticarComo();

        // Vida útil 110 días al 30 % => ventana de 33 días; llega con 10 días restantes
        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra([
            'fecha_fabricacion' => now()->subDays(100)->toDateString(),
            'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.lote.estado', Lote::ESTADO_PROXIMO_A_VENCER);

        $this->assertDatabaseHas('alertas', [
            'insumo_id' => $this->harina->id,
            'tipo_alerta' => Alerta::PREVENTIVA_VENCIMIENTO,
        ]);
    }

    public function test_rechaza_lotes_vencidos_y_fechas_incoherentes(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra([
            'fecha_vencimiento' => now()->subDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['fecha_vencimiento']);

        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra([
            'fecha_fabricacion' => now()->addDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['fecha_fabricacion']);

        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra([
            'fecha_fabricacion' => now()->subDays(5)->toDateString(),
            'fecha_vencimiento' => '15/12/2026',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['fecha_vencimiento']);

        $this->assertDatabaseCount('lotes', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_valida_insumo_proveedor_y_cantidad(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/insumos/ingreso', [
            'insumo_id' => 99999,
            'proveedor_id' => 99999,
            'cantidad' => 0,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['insumo_id', 'proveedor_id', 'cantidad']);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/insumos/ingreso', $this->datosCompra())->assertUnauthorized();
    }

    private function datosCompra(array $cambios = []): array
    {
        return array_merge([
            'insumo_id' => $this->harina->id,
            'proveedor_id' => $this->molino->id,
            'cantidad' => 50,
            'fecha_fabricacion' => now()->subDays(2)->toDateString(),
            'fecha_vencimiento' => now()->addDays(120)->toDateString(),
        ], $cambios);
    }
}
