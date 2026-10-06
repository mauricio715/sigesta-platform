<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\RestringirTokensSoloLectura;
use App\Models\Insumo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Token de servicio del asistente de IA, resumen de inventario
 * y búsqueda de lotes (las consultas que usa el microservicio).
 */
class ServicioIaApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    // =====================================================================
    //  Comando sigesta:token-ia
    // =====================================================================

    public function test_el_comando_emite_un_token_de_solo_lectura_con_expiracion(): void
    {
        $this->artisan('sigesta:token-ia', ['--dias' => 30])
            ->expectsOutputToContain('IA_SERVICE_TOKEN=')
            ->assertSuccessful();

        $servicio = User::where('email', config('sigesta.ia_service.email'))->firstOrFail();
        $token = $servicio->tokens()->sole();

        $this->assertSame(User::ROLE_OPERADOR, $servicio->role);
        $this->assertSame([RestringirTokensSoloLectura::HABILIDAD], $token->abilities);
        $this->assertSame(now()->addDays(30)->toDateString(), $token->expires_at->toDateString());
    }

    public function test_regenerar_el_token_revoca_el_anterior_y_revocar_los_elimina(): void
    {
        $this->artisan('sigesta:token-ia')->assertSuccessful();
        $this->artisan('sigesta:token-ia')->assertSuccessful();

        $servicio = User::where('email', config('sigesta.ia_service.email'))->firstOrFail();
        $this->assertSame(1, $servicio->tokens()->count());

        $this->artisan('sigesta:token-ia', ['--revocar' => true])->assertSuccessful();
        $this->assertSame(0, $servicio->tokens()->count());
    }

    public function test_el_comando_rechaza_validez_fuera_de_rango(): void
    {
        $this->artisan('sigesta:token-ia', ['--dias' => 1000])->assertFailed();
    }

    // =====================================================================
    //  Restricción de solo lectura
    // =====================================================================

    public function test_el_token_de_solo_lectura_consulta_pero_no_escribe(): void
    {
        $escenario = $this->crearEscenarioProduccion();
        $token = $this->tokenDeServicio();

        $this->withToken($token)->getJson('/api/v1/inventario/resumen')->assertOk();
        $this->withToken($token)->getJson('/api/v1/alertas')->assertOk();
        $this->withToken($token)->getJson('/api/v1/movimientos')->assertOk();
        $this->withToken($token)->getJson("/api/v1/trazabilidad/insumo/{$escenario['loteLecheA']->id}")->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/produccion', ['producto_id' => $escenario['pan']->id, 'cantidad' => 100])
            ->assertForbidden()
            ->assertJsonPath('message', 'Este token es de solo lectura: no puede registrar ni modificar información.');

        $this->withToken($token)
            ->postJson('/api/v1/mermas', ['lote_id' => $escenario['loteLecheA']->id, 'cantidad' => 1, 'motivo' => 'DETERIORO'])
            ->assertForbidden();

        $this->assertDatabaseCount('ordenes_produccion', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_el_servicio_no_accede_a_rutas_de_administrador(): void
    {
        $token = $this->tokenDeServicio();

        $this->withToken($token)->getJson('/api/v1/usuarios')->assertForbidden();
    }

    public function test_un_operador_normal_conserva_sus_permisos_de_escritura(): void
    {
        $escenario = $this->crearEscenarioProduccion();
        $operador = $this->crearUsuario();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $operador->email, 'password' => 'password'])
            ->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/produccion', ['producto_id' => $escenario['pan']->id, 'cantidad' => 100])
            ->assertCreated();
    }

    // =====================================================================
    //  Resumen de inventario y búsqueda de lotes
    // =====================================================================

    public function test_resumen_de_inventario_muestra_lotes_en_orden_fefo(): void
    {
        $this->autenticarComo();
        $escenario = $this->crearEscenarioProduccion();
        $escenario['leche']->update(['stock_minimo' => 100]); // 43 lt < 100 lt

        $response = $this->getJson('/api/v1/inventario/resumen')
            ->assertOk()
            ->assertJsonPath('data.totales.insumos', 3)
            ->assertJsonPath('data.totales.productos', 1)
            ->assertJsonPath('data.totales.items_bajo_stock_minimo', 1);

        $leche = collect($response->json('data.insumos'))->firstWhere('codigo', 'INS-LEC');

        $this->assertSame('43.000', $leche['stock_actual']);
        $this->assertTrue($leche['bajo_stock_minimo']);
        $this->assertSame(2, $leche['lotes_vigentes']);
        $this->assertSame(['LEC-B', 'LEC-A'], array_column($leche['lotes_fefo'], 'codigo_lote'));
        $this->assertSame(10, $leche['lotes_fefo'][0]['dias_para_vencer']);
    }

    public function test_resumen_filtra_por_tipo_y_bajo_stock(): void
    {
        $this->autenticarComo();
        $escenario = $this->crearEscenarioProduccion();
        $escenario['azucar']->update(['stock_minimo' => 50]);

        $this->getJson('/api/v1/inventario/resumen?tipo=INSUMO&solo_bajo_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.insumos')
            ->assertJsonPath('data.insumos.0.codigo', 'INS-AZU')
            ->assertJsonCount(0, 'data.productos');
    }

    public function test_resumen_cuenta_lotes_vencidos_aun_no_marcados(): void
    {
        $this->autenticarComo();
        $sal = Insumo::create(['codigo' => 'INS-SAL', 'nombre' => 'Sal', 'unidad_medida' => 'kg']);
        $this->crearLoteInsumo($sal, 'SAL-VENC', -3, 5, diasDesdeFabricacion: 30);

        $this->getJson('/api/v1/inventario/resumen')
            ->assertJsonPath('data.totales.lotes_vencidos_con_saldo', 1)
            ->assertJsonPath('data.insumos.0.lotes_vigentes', 0);
    }

    public function test_busca_lotes_por_codigo(): void
    {
        $this->autenticarComo();
        $this->crearEscenarioProduccion();

        $this->getJson('/api/v1/lotes?buscar=LEC')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.insumo.nombre', 'Leche entera');

        $this->getJson('/api/v1/lotes?buscar=AZU-001')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo_lote', 'AZU-001');
    }

    // ---------- Helpers ----------

    private function tokenDeServicio(): string
    {
        $servicio = $this->crearUsuario(User::ROLE_OPERADOR);

        return $servicio->createToken('ia-service', [RestringirTokensSoloLectura::HABILIDAD])->plainTextToken;
    }
}
