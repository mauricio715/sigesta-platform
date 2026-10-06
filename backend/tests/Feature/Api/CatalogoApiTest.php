<?php

namespace Tests\Feature\Api;

use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    public function test_admin_registra_un_insumo_con_valores_por_defecto(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);

        $this->postJson('/api/v1/insumos', [
            'codigo' => 'INS-SAL',
            'nombre' => 'Sal yodada',
            'unidad_medida' => 'kg',
            'stock_minimo' => 5,
            'stock_actual' => 999, // debe ignorarse: el stock sale de los lotes
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.codigo', 'INS-SAL')
            ->assertJsonPath('data.stock_actual', '0.000')
            ->assertJsonPath('data.stock_minimo', '5.000')
            ->assertJsonPath('data.porcentaje_alerta_preventiva', '30.00')
            ->assertJsonPath('data.bajo_stock_minimo', true);
    }

    public function test_valida_codigo_duplicado_y_unidad_de_medida(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        Insumo::create(['codigo' => 'INS-SAL', 'nombre' => 'Sal', 'unidad_medida' => 'kg']);

        $this->postJson('/api/v1/insumos', [
            'codigo' => 'INS-SAL',
            'nombre' => 'Otra sal',
            'unidad_medida' => 'toneladas',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo', 'unidad_medida']);
    }

    public function test_actualiza_parcialmente_un_insumo(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $sal = Insumo::create(['codigo' => 'INS-SAL', 'nombre' => 'Sal', 'unidad_medida' => 'kg']);

        $this->patchJson("/api/v1/insumos/{$sal->id}", ['nombre' => 'Sal yodada fina'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Sal yodada fina')
            ->assertJsonPath('data.codigo', 'INS-SAL');
    }

    public function test_no_elimina_un_insumo_con_lotes_pero_si_uno_sin_historial(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $conLotes = Insumo::create(['codigo' => 'INS-LEC', 'nombre' => 'Leche', 'unidad_medida' => 'lt']);
        $this->crearLoteInsumo($conLotes, 'LEC-01', 10, 20);
        $sinLotes = Insumo::create(['codigo' => 'INS-SAL', 'nombre' => 'Sal', 'unidad_medida' => 'kg']);

        $this->deleteJson("/api/v1/insumos/{$conLotes->id}")->assertStatus(409)->assertJsonPath('status', 'error');
        $this->deleteJson("/api/v1/insumos/{$sinLotes->id}")->assertOk();

        $this->assertDatabaseHas('insumos', ['id' => $conLotes->id]);
        $this->assertDatabaseMissing('insumos', ['id' => $sinLotes->id]);
    }

    public function test_lista_los_lotes_vigentes_de_un_insumo_en_orden_fefo(): void
    {
        $this->autenticarComo();
        $leche = Insumo::create(['codigo' => 'INS-LEC', 'nombre' => 'Leche', 'unidad_medida' => 'lt']);
        $this->crearLoteInsumo($leche, 'LEC-30D', 30, 20);
        $this->crearLoteInsumo($leche, 'LEC-10D', 10, 20);
        $this->crearLoteInsumo($leche, 'LEC-VENC', -1, 20, diasDesdeFabricacion: 10);

        $response = $this->getJson("/api/v1/insumos/{$leche->id}/lotes")->assertOk();

        $this->assertSame(['LEC-10D', 'LEC-30D'], array_column($response->json('data'), 'codigo_lote'));
        $this->assertSame(10, $response->json('data.0.dias_para_vencer'));
    }

    public function test_consulta_la_receta_activa_de_un_producto(): void
    {
        $this->autenticarComo();
        $escenario = $this->crearEscenarioProduccion();
        Receta::create([
            'producto_id' => $escenario['pan']->id,
            'nombre_receta' => 'Fórmula antigua',
            'rendimiento_base' => 50,
            'activa' => false,
        ]);

        $this->getJson("/api/v1/productos/{$escenario['pan']->id}/recetas")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre_receta', 'Pan de leche estándar')
            ->assertJsonPath('data.0.formula.0.nombre', 'Harina de trigo')
            ->assertJsonPath('data.0.formula.2.cantidad_requerida', '0.800');
    }

    public function test_recurso_inexistente_devuelve_404_estandar(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/productos/99999')
            ->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_busca_productos_por_nombre_o_codigo(): void
    {
        $this->autenticarComo();
        Producto::create(['codigo' => 'PRD-PAN', 'nombre' => 'Pan de leche', 'unidad_medida' => 'unidad', 'dias_vida_util' => 5]);
        Producto::create(['codigo' => 'PRD-QUE', 'nombre' => 'Queso fresco', 'unidad_medida' => 'kg', 'dias_vida_util' => 15]);

        $this->getJson('/api/v1/productos?buscar=queso')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'PRD-QUE')
            ->assertJsonPath('meta.total', 1);
    }
}
