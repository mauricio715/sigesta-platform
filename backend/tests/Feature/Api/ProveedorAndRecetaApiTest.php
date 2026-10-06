<?php

namespace Tests\Feature\Api;

use App\Models\Insumo;
use App\Models\Proveedor;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProveedorAndRecetaApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    // =====================================================================
    //  Proveedores
    // =====================================================================

    public function test_admin_gestiona_el_ciclo_completo_de_un_proveedor(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);

        $id = $this->postJson('/api/v1/proveedores', [
            'codigo_proveedor' => 'PRV-010',
            'razon_social' => 'Lácteos del Valle',
            'nit' => '3002002',
            'telefono' => '4-4123456',
            'email' => 'ventas@lacteosvalle.bo',
        ])
            ->assertCreated()
            ->assertJsonPath('data.razon_social', 'Lácteos del Valle')
            ->json('data.id');

        $this->getJson("/api/v1/proveedores/{$id}")
            ->assertOk()
            ->assertJsonPath('data.codigo_proveedor', 'PRV-010')
            ->assertJsonPath('data.lotes_count', 0);

        $this->patchJson("/api/v1/proveedores/{$id}", ['telefono' => '4-4999999'])
            ->assertOk()
            ->assertJsonPath('data.telefono', '4-4999999')
            ->assertJsonPath('data.razon_social', 'Lácteos del Valle');

        $this->getJson('/api/v1/proveedores?buscar=valle')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/proveedores/{$id}")->assertOk();
        $this->assertDatabaseMissing('proveedores', ['id' => $id]);
    }

    public function test_no_elimina_un_proveedor_con_lotes(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $proveedor = Proveedor::create(['codigo_proveedor' => 'PRV-001', 'razon_social' => 'Molinos Andinos']);
        $harina = Insumo::create(['codigo' => 'INS-HAR', 'nombre' => 'Harina', 'unidad_medida' => 'kg']);
        $this->crearLoteInsumo($harina, 'HAR-01', 90, 50)->update(['proveedor_id' => $proveedor->id]);

        $this->deleteJson("/api/v1/proveedores/{$proveedor->id}")
            ->assertStatus(409)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id]);
    }

    public function test_valida_codigo_duplicado_de_proveedor(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        Proveedor::create(['codigo_proveedor' => 'PRV-001', 'razon_social' => 'Molinos Andinos']);

        $this->postJson('/api/v1/proveedores', ['codigo_proveedor' => 'PRV-001', 'razon_social' => 'Otro'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo_proveedor']);
    }

    public function test_operador_consulta_proveedores_pero_no_los_modifica(): void
    {
        $this->autenticarComo(User::ROLE_OPERADOR);
        $proveedor = Proveedor::create(['codigo_proveedor' => 'PRV-001', 'razon_social' => 'Molinos Andinos']);

        $this->getJson('/api/v1/proveedores')->assertOk();
        $this->postJson('/api/v1/proveedores', ['codigo_proveedor' => 'PRV-002', 'razon_social' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/proveedores/{$proveedor->id}")->assertForbidden();
    }

    // =====================================================================
    //  Recetas
    // =====================================================================

    public function test_nueva_version_activa_desactiva_la_anterior_y_rige_la_produccion(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $escenario = $this->crearEscenarioProduccion();
        $original = $escenario['receta'];

        // V2: menos azúcar
        $response = $this->postJson('/api/v1/recetas', [
            'producto_id' => $escenario['pan']->id,
            'nombre_receta' => 'Pan de leche v2 (bajo en azúcar)',
            'rendimiento_base' => 100,
            'insumos' => [
                ['insumo_id' => $escenario['harina']->id, 'cantidad_requerida' => 5],
                ['insumo_id' => $escenario['leche']->id, 'cantidad_requerida' => 2],
                ['insumo_id' => $escenario['azucar']->id, 'cantidad_requerida' => 0.5],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.activa', true)
            ->assertJsonPath('data.producto.nombre', 'Pan de leche')
            ->assertJsonCount(3, 'data.formula')
            ->assertJsonPath('data.formula.2.cantidad_requerida', '0.500');

        $nuevaId = $response->json('data.id');
        $this->assertFalse($original->fresh()->activa);

        // Solo la v2 aparece como receta activa del producto
        $this->getJson("/api/v1/productos/{$escenario['pan']->id}/recetas")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $nuevaId);

        // La producción usa la v2: 100 panes consumen 0.5 kg de azúcar
        $this->postJson('/api/v1/produccion', ['producto_id' => $escenario['pan']->id, 'cantidad' => 100])
            ->assertCreated()
            ->assertJsonPath('data.receta.id', $nuevaId);

        $this->assertSame('9.500', $escenario['loteAzucar']->fresh()->cantidad_actual);
    }

    public function test_reactivar_una_version_desactiva_las_demas(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $escenario = $this->crearEscenarioProduccion();
        $original = $escenario['receta'];

        $nuevaId = $this->postJson('/api/v1/recetas', [
            'producto_id' => $escenario['pan']->id,
            'nombre_receta' => 'Pan de leche v2',
            'rendimiento_base' => 50,
            'insumos' => [['insumo_id' => $escenario['harina']->id, 'cantidad_requerida' => 2.5]],
        ])->json('data.id');

        $this->patchJson("/api/v1/recetas/{$original->id}/estado", ['activa' => true])
            ->assertOk()
            ->assertJsonPath('data.activa', true);

        $this->assertFalse(Receta::find($nuevaId)->activa);
        $this->assertSame(1, Receta::where('producto_id', $escenario['pan']->id)->where('activa', true)->count());

        // Historial completo de versiones
        $this->getJson("/api/v1/recetas?producto_id={$escenario['pan']->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_crear_version_inactiva_no_altera_la_receta_vigente(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $escenario = $this->crearEscenarioProduccion();

        $this->postJson('/api/v1/recetas', [
            'producto_id' => $escenario['pan']->id,
            'nombre_receta' => 'Borrador v3',
            'rendimiento_base' => 100,
            'activa' => false,
            'insumos' => [['insumo_id' => $escenario['harina']->id, 'cantidad_requerida' => 6]],
        ])->assertCreated()->assertJsonPath('data.activa', false);

        $this->assertTrue($escenario['receta']->fresh()->activa);
    }

    public function test_valida_la_formula_de_la_receta(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $escenario = $this->crearEscenarioProduccion();

        $this->postJson('/api/v1/recetas', [
            'producto_id' => $escenario['pan']->id,
            'nombre_receta' => 'Inválida',
            'rendimiento_base' => 0,
            'insumos' => [
                ['insumo_id' => $escenario['harina']->id, 'cantidad_requerida' => 5],
                ['insumo_id' => $escenario['harina']->id, 'cantidad_requerida' => -1],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'rendimiento_base',
                'insumos.0.insumo_id',
                'insumos.1.insumo_id',
                'insumos.1.cantidad_requerida',
            ]);

        $this->postJson('/api/v1/recetas', [
            'producto_id' => $escenario['pan']->id,
            'nombre_receta' => 'Sin insumos',
            'rendimiento_base' => 100,
            'insumos' => [],
        ])->assertJsonValidationErrors(['insumos']);
    }

    public function test_operador_consulta_recetas_pero_no_las_crea_ni_cambia(): void
    {
        $this->autenticarComo(User::ROLE_OPERADOR);
        $escenario = $this->crearEscenarioProduccion();

        $this->getJson("/api/v1/recetas/{$escenario['receta']->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.formula');

        $this->postJson('/api/v1/recetas', [
            'producto_id' => $escenario['pan']->id,
            'nombre_receta' => 'X',
            'rendimiento_base' => 1,
            'insumos' => [['insumo_id' => $escenario['harina']->id, 'cantidad_requerida' => 1]],
        ])->assertForbidden();

        $this->patchJson("/api/v1/recetas/{$escenario['receta']->id}/estado", ['activa' => false])->assertForbidden();
    }
}
