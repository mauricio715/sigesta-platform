<?php

namespace Tests\Feature\Api;

use App\Models\Cliente;
use App\Models\Despacho;
use App\Models\Lote;
use App\Models\ReporteEmitido;
use App\Reportes\Emision;
use App\Reportes\PdfRenderer;
use App\Services\DespachoService;
use App\Services\MermaService;
use App\Services\ProduccionService;
use App\Services\ReporteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportesApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private array $escenario;
    private Cliente $prado;
    private int $lotePanId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->escenario = $this->crearEscenarioProduccion();
        $operador = $this->crearUsuario();
        $this->prado = Cliente::create([
            'codigo_cliente' => 'CLI-001', 'razon_social' => 'Supermercado El Prado',
            'nit_ci' => '1023456789', 'telefono' => '4-4502020',
        ]);

        $orden = app(ProduccionService::class)->ejecutarOrdenProduccion($this->escenario['pan']->id, 250, $operador->id);
        $this->lotePanId = $orden->lote_producto_id;

        app(DespachoService::class)->registrarDespacho(
            $this->prado->id,
            [['producto_id' => $this->escenario['pan']->id, 'cantidad' => 150, 'precio_unitario' => 0.8]],
            $operador->id,
        );

        app(MermaService::class)->registrarMerma($this->lotePanId, 5, 'DANO_EMPAQUE', $operador->id, 'Bolsas rotas en el apilado.');
    }

    // =====================================================================
    //  Descargas
    // =====================================================================

    public function test_descarga_el_informe_de_trazabilidad_en_pdf_y_registra_la_emision(): void
    {
        $usuario = $this->autenticarComo();

        $response = $this->get("/api/v1/reportes/trazabilidad/{$this->escenario['loteLecheB']->id}");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment; filename="trazabilidad_LEC-B_', $response->headers->get('Content-Disposition'));

        $codigo = $response->headers->get('X-Codigo-Verificacion');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $codigo);
        $this->assertDatabaseHas('reportes_emitidos', [
            'codigo' => $codigo,
            'tipo' => 'trazabilidad',
            'formato' => 'pdf',
            'user_id' => $usuario->id,
        ]);
    }

    public function test_descarga_reportes_en_excel_con_valores_numericos_reales(): void
    {
        $this->autenticarComo();

        $response = $this->get('/api/v1/reportes/despachos?formato=xlsx');

        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $response->getContent()); // un .xlsx es un ZIP

        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($archivo, $response->getContent());
        $libro = IOFactory::load($archivo);
        unlink($archivo);

        $this->assertSame(['Resumen', 'Resumen por cliente', 'Detalle por lote entregado'], $libro->getSheetNames());

        // Hoja de clientes: encabezado en la fila 5, datos desde la 6
        $hoja = $libro->getSheetByName('Resumen por cliente');
        $this->assertSame('Cliente', $hoja->getCell('A5')->getValue());
        $this->assertSame('Supermercado El Prado', $hoja->getCell('A6')->getValue());
        $this->assertEqualsWithDelta(120.0, $hoja->getCell('C6')->getValue(), 0.001); // 150 × 0,80 (número, no texto)
    }

    public function test_cada_reporte_responde_en_ambos_formatos(): void
    {
        $this->autenticarComo();
        $hoy = now()->toDateString();

        foreach ([
            '/api/v1/reportes/inventario',
            "/api/v1/reportes/kardex?tipo_item=PRODUCTO&item_id={$this->escenario['pan']->id}",
            "/api/v1/reportes/despachos?fecha_inicio={$hoy}&fecha_fin={$hoy}",
            "/api/v1/reportes/mermas?fecha_inicio={$hoy}",
            "/api/v1/reportes/trazabilidad/{$this->lotePanId}",
        ] as $ruta) {
            $separador = str_contains($ruta, '?') ? '&' : '?';
            $this->get("{$ruta}{$separador}formato=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->get("{$ruta}{$separador}formato=xlsx")->assertOk();
        }

        $this->assertSame(10, ReporteEmitido::count());
    }

    // =====================================================================
    //  Contenido
    // =====================================================================

    public function test_el_informe_de_trazabilidad_incluye_clientes_contactos_y_firmas(): void
    {
        $reporte = app(ReporteService::class)->trazabilidad(Lote::find($this->escenario['loteLecheB']->id));
        $html = app(PdfRenderer::class)->html($reporte, new Emision('ABCD-1234-EF56', now(), 'Ana Rojas', config('sigesta.empresa')));

        $this->assertStringContainsString('Informe de trazabilidad hacia adelante', $html);
        $this->assertStringContainsString('Supermercado El Prado', $html);
        $this->assertStringContainsString('1023456789', $html);
        $this->assertStringContainsString('4-4502020', $html);
        $this->assertStringContainsString('Responsable de calidad e inocuidad', $html);
        $this->assertStringContainsString('ABCD-1234-EF56', $html);

        // Saldo en planta del pan elaborado: 250 - 150 - 5 = 95
        $this->assertStringContainsString('95 u.', implode(' ', $reporte->notas));
    }

    public function test_el_kardex_de_un_item_calcula_el_saldo_acumulado(): void
    {
        $reporte = app(ReporteService::class)->kardex(['tipo_item' => 'PRODUCTO', 'item_id' => $this->escenario['pan']->id], 1000);
        $filas = $reporte->secciones[0]->filas;

        // Producción +250, despacho -150, merma -5
        $this->assertSame(['250.000', '100.000', '95.000'], array_column($filas, 'saldo'));
        $this->assertSame('250.000', $reporte->secciones[0]->totales['entrada']);
        $this->assertSame('155.000', $reporte->secciones[0]->totales['salida']);
    }

    public function test_el_kardex_parte_del_saldo_anterior_al_periodo(): void
    {
        $this->travel(2)->days();
        app(DespachoService::class)->registrarDespacho(
            $this->prado->id,
            [['producto_id' => $this->escenario['pan']->id, 'cantidad' => 20]],
            $this->crearUsuario()->id,
        );

        $reporte = app(ReporteService::class)->kardex([
            'tipo_item' => 'PRODUCTO',
            'item_id' => $this->escenario['pan']->id,
            'fecha_inicio' => now()->toDateString(),
        ], 1000);

        $this->assertCount(1, $reporte->secciones[0]->filas);
        $this->assertSame('75.000', $reporte->secciones[0]->filas[0]['saldo']); // 95 previos - 20
        $this->assertContains(['Saldo al inicio', '95'], $reporte->resumen);
    }

    public function test_los_despachos_anulados_se_listan_pero_no_suman(): void
    {
        $despacho = Despacho::first();
        $despacho->update(['estado' => Despacho::ESTADO_ANULADO]);

        $reporte = app(ReporteService::class)->despachos([], 1000);

        $this->assertSame([], $reporte->secciones[0]->filas); // resumen por cliente vacío
        $this->assertSame('Anulado', $reporte->secciones[1]->filas[0]['estado']);
        $this->assertContains(['Despachos anulados', '1'], $reporte->resumen);
    }

    public function test_el_registro_de_mermas_agrupa_por_motivo(): void
    {
        $reporte = app(ReporteService::class)->mermas([], 1000);

        $this->assertSame('Daño de empaque', $reporte->secciones[0]->filas[0]['motivo']);
        $this->assertSame('5.000', $reporte->secciones[0]->filas[0]['cantidad']);
        $this->assertSame('Bolsas rotas en el apilado.', $reporte->secciones[1]->filas[0]['observacion']);
    }

    // =====================================================================
    //  Validaciones y verificación
    // =====================================================================

    public function test_rechaza_formatos_invalidos_y_reportes_demasiado_grandes(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/reportes/inventario?formato=docx')->assertUnprocessable()->assertJsonValidationErrors(['formato']);

        config(['sigesta.reportes.max_filas_pdf' => 3]);
        $this->getJson('/api/v1/reportes/kardex?formato=pdf')
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, ReporteEmitido::count());
    }

    public function test_verifica_la_autenticidad_de_un_reporte(): void
    {
        $usuario = $this->autenticarComo();
        $codigo = $this->get('/api/v1/reportes/inventario')->headers->get('X-Codigo-Verificacion');

        $this->getJson('/api/v1/reportes/verificar/' . strtolower(str_replace('-', '', $codigo)))
            ->assertOk()
            ->assertJsonPath('data.codigo', $codigo)
            ->assertJsonPath('data.tipo', 'inventario')
            ->assertJsonPath('data.emitido_por', $usuario->name);

        $this->getJson('/api/v1/reportes/verificar/0000-0000-0000')->assertNotFound();
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/reportes/inventario')->assertUnauthorized();
    }
}
