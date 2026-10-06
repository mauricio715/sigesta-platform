<?php

namespace Tests\Feature\Api;

use App\Models\Cliente;
use App\Models\OrdenProduccion;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\DespachoService;
use App\Services\ProduccionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrazabilidadApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private array $escenario;
    private OrdenProduccion $orden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->escenario = $this->crearEscenarioProduccion();

        $lecheria = Proveedor::create(['codigo_proveedor' => 'PRV-002', 'razon_social' => 'Lácteos del Valle']);
        $this->escenario['loteLecheA']->update(['proveedor_id' => $lecheria->id]);
        $this->escenario['loteLecheB']->update(['proveedor_id' => $lecheria->id]);

        $cliente = Cliente::create(['codigo_cliente' => 'CLI-001', 'razon_social' => 'Supermercado El Prado']);
        $responsable = $this->crearUsuario(User::ROLE_OPERADOR);

        $this->orden = app(ProduccionService::class)
            ->ejecutarOrdenProduccion($this->escenario['pan']->id, 250, $responsable->id);

        app(DespachoService::class)->registrarDespacho(
            $cliente->id,
            [['producto_id' => $this->escenario['pan']->id, 'cantidad' => 150]],
            $responsable->id,
        );
    }

    public function test_rastrea_un_lote_de_insumo_hasta_el_cliente_final(): void
    {
        $this->autenticarComo();

        $this->getJson("/api/v1/trazabilidad/insumo/{$this->escenario['loteLecheB']->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.lote_insumo.codigo_lote', 'LEC-B')
            ->assertJsonPath('data.lote_insumo.proveedor.razon_social', 'Lácteos del Valle')
            ->assertJsonPath('data.producciones.0.orden_produccion.codigo_orden', $this->orden->codigo_orden)
            ->assertJsonPath('data.producciones.0.cantidad_insumo_consumida', '3.000')
            ->assertJsonPath('data.producciones.0.lote_producto.id', $this->orden->lote_producto_id)
            ->assertJsonPath('data.clientes_afectados.0.razon_social', 'Supermercado El Prado')
            ->assertJsonPath('data.clientes_afectados.0.entregas.0.cantidad', '150.000')
            ->assertJsonPath('data.resumen.clientes_afectados', 1);
    }

    public function test_rastrea_un_lote_de_producto_hasta_sus_insumos_y_proveedores(): void
    {
        $this->autenticarComo();

        $response = $this->getJson("/api/v1/trazabilidad/producto/{$this->orden->lote_producto_id}")
            ->assertOk()
            ->assertJsonPath('data.orden_produccion.codigo_orden', $this->orden->codigo_orden)
            ->assertJsonPath('data.receta.nombre_receta', 'Pan de leche estándar')
            ->assertJsonCount(4, 'data.insumos_origen');

        $lotesOrigen = array_column(array_column($response->json('data.insumos_origen'), 'lote'), 'codigo_lote');
        $this->assertEqualsCanonicalizing(['HAR-001', 'LEC-B', 'LEC-A', 'AZU-001'], $lotesOrigen);

        $proveedorLecheB = collect($response->json('data.insumos_origen'))
            ->firstWhere('lote.codigo_lote', 'LEC-B')['proveedor']['razon_social'];
        $this->assertSame('Lácteos del Valle', $proveedorLecheB);
    }

    public function test_tipo_de_lote_incorrecto_devuelve_422(): void
    {
        $this->autenticarComo();

        $this->getJson("/api/v1/trazabilidad/insumo/{$this->orden->lote_producto_id}")
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');

        $this->getJson("/api/v1/trazabilidad/producto/{$this->escenario['loteHarina']->id}")
            ->assertUnprocessable();
    }

    public function test_lote_inexistente_devuelve_404(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/trazabilidad/insumo/99999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_parametro_no_numerico_no_coincide_con_la_ruta(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/trazabilidad/insumo/abc')->assertNotFound();
    }
}
