<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\Producto;
use App\Services\AlertaService;
use App\Services\LoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaServiceTest extends TestCase
{
    use RefreshDatabase;

    private AlertaService $alertas;
    private LoteService $lotes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alertas = app(AlertaService::class);
        $this->lotes = app(LoteService::class);
    }

    public function test_ventana_preventiva_es_proporcional_a_la_vida_util(): void
    {
        $pan = Producto::create(['codigo' => 'PRD-PAN', 'nombre' => 'Pan de leche', 'unidad_medida' => 'unidad', 'dias_vida_util' => 5]);
        $harina = Insumo::create(['codigo' => 'INS-HAR', 'nombre' => 'Harina de trigo', 'unidad_medida' => 'kg']);

        $lotePan = $this->crearLote(Lote::TIPO_PRODUCTO, $pan->id, 'PAN-01', diasDesdeFabricacion: 0, diasParaVencer: 5);
        $loteHarina = $this->crearLote(Lote::TIPO_INSUMO, $harina->id, 'HAR-01', diasDesdeFabricacion: 0, diasParaVencer: 180);

        // 30 % por defecto: 5 días => 2 días (ceil 1.5); 180 días => 54 días
        $this->assertSame(2, $this->alertas->diasVentanaPreventiva($lotePan->fresh()));
        $this->assertSame(54, $this->alertas->diasVentanaPreventiva($loteHarina->fresh()));
    }

    public function test_marca_proximo_a_vencer_al_entrar_en_la_ventana_dinamica(): void
    {
        $pan = Producto::create(['codigo' => 'PRD-PAN', 'nombre' => 'Pan de leche', 'unidad_medida' => 'unidad', 'dias_vida_util' => 5]);
        $lote = $this->crearLote(Lote::TIPO_PRODUCTO, $pan->id, 'PAN-01', diasDesdeFabricacion: 0, diasParaVencer: 5);

        // Día 2: quedan 3 días > ventana de 2 => sigue ACTIVO
        $this->travel(2)->days();
        $this->alertas->evaluarVencimientos();
        $this->assertSame(Lote::ESTADO_ACTIVO, $lote->fresh()->estado);
        $this->assertDatabaseCount('alertas', 0);

        // Día 3: quedan 2 días => PROXIMO_A_VENCER + alerta preventiva
        $this->travel(1)->days();
        $resumen = $this->alertas->evaluarVencimientos();

        $this->assertSame(Lote::ESTADO_PROXIMO_A_VENCER, $lote->fresh()->estado);
        $this->assertSame(1, $resumen['proximos_a_vencer']);
        $this->assertSame(1, $resumen['alertas_nuevas']);
        $this->assertDatabaseHas('alertas', [
            'lote_id' => $lote->id,
            'producto_id' => $pan->id,
            'tipo_alerta' => Alerta::PREVENTIVA_VENCIMIENTO,
            'nivel_prioridad' => Alerta::PRIORIDAD_MEDIA,
        ]);

        // Día 4: misma alerta actualizada (no duplicada) y con prioridad ALTA
        $this->travel(1)->days();
        $this->alertas->evaluarVencimientos();
        $this->assertSame(1, Alerta::where('tipo_alerta', Alerta::PREVENTIVA_VENCIMIENTO)->count());
        $this->assertSame(Alerta::PRIORIDAD_ALTA, Alerta::first()->nivel_prioridad);
    }

    public function test_un_insumo_de_vida_larga_se_alerta_con_mucha_anticipacion(): void
    {
        $harina = Insumo::create(['codigo' => 'INS-HAR', 'nombre' => 'Harina de trigo', 'unidad_medida' => 'kg']);

        // Vida útil 180 días, quedan 30: una ventana fija de 15 días no lo detectaría
        $lote = $this->crearLote(Lote::TIPO_INSUMO, $harina->id, 'HAR-01', diasDesdeFabricacion: 150, diasParaVencer: 30);

        $this->alertas->evaluarVencimientos();

        $this->assertSame(Lote::ESTADO_PROXIMO_A_VENCER, $lote->fresh()->estado);
    }

    public function test_respeta_el_porcentaje_configurado_por_item(): void
    {
        $harina = Insumo::create([
            'codigo' => 'INS-HAR', 'nombre' => 'Harina de trigo', 'unidad_medida' => 'kg',
            'porcentaje_alerta_preventiva' => 10, // 180 días => 18 días
        ]);

        $lote = $this->crearLote(Lote::TIPO_INSUMO, $harina->id, 'HAR-01', diasDesdeFabricacion: 150, diasParaVencer: 30);

        $this->alertas->evaluarVencimientos();

        $this->assertSame(Lote::ESTADO_ACTIVO, $lote->fresh()->estado);
    }

    public function test_marca_vencido_genera_alerta_critica_y_descuenta_del_stock(): void
    {
        $leche = Insumo::create(['codigo' => 'INS-LEC', 'nombre' => 'Leche entera', 'unidad_medida' => 'lt', 'stock_minimo' => 5]);
        $lote = $this->crearLote(Lote::TIPO_INSUMO, $leche->id, 'LEC-01', diasDesdeFabricacion: 5, diasParaVencer: 2, cantidad: 20);
        $this->lotes->recalcularStockInsumo($leche);
        $this->assertSame('20.000', $leche->fresh()->stock_actual);

        $this->travel(3)->days();
        $resumen = $this->alertas->evaluarVencimientos();

        $this->assertSame(1, $resumen['vencidos']);
        $this->assertSame(Lote::ESTADO_VENCIDO, $lote->fresh()->estado);
        $this->assertDatabaseHas('alertas', [
            'lote_id' => $lote->id,
            'tipo_alerta' => Alerta::VENCIMIENTO_CRITICO,
            'nivel_prioridad' => Alerta::PRIORIDAD_ALTA,
        ]);

        // El lote vencido deja de contar como stock utilizable => alerta de mínimo
        $this->assertSame('0.000', $leche->fresh()->stock_actual);
        $this->assertDatabaseHas('alertas', ['insumo_id' => $leche->id, 'tipo_alerta' => Alerta::STOCK_MINIMO]);
    }

    public function test_el_comando_de_consola_se_ejecuta_correctamente(): void
    {
        $pan = Producto::create(['codigo' => 'PRD-PAN', 'nombre' => 'Pan de leche', 'unidad_medida' => 'unidad', 'dias_vida_util' => 5]);
        $this->crearLote(Lote::TIPO_PRODUCTO, $pan->id, 'PAN-01', diasDesdeFabricacion: 4, diasParaVencer: 1);

        $this->artisan('sigesta:evaluar-alertas')
            ->expectsOutputToContain('Evaluando vencimientos')
            ->assertSuccessful();

        $this->assertDatabaseCount('alertas', 1);
    }

    // ---------- Helpers ----------

    private function crearLote(
        string $tipo,
        int $itemId,
        string $codigo,
        int $diasDesdeFabricacion,
        int $diasParaVencer,
        float $cantidad = 100,
    ): Lote {
        return Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => $tipo,
            'insumo_id' => $tipo === Lote::TIPO_INSUMO ? $itemId : null,
            'producto_id' => $tipo === Lote::TIPO_PRODUCTO ? $itemId : null,
            'fecha_fabricacion' => now()->subDays($diasDesdeFabricacion)->toDateString(),
            'fecha_vencimiento' => now()->addDays($diasParaVencer)->toDateString(),
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => Lote::ESTADO_ACTIVO,
        ]);
    }
}
