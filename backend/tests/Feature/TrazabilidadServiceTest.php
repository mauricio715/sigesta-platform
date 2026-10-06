<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Despacho;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Receta;
use App\Models\User;
use App\Services\DespachoService;
use App\Services\LoteService;
use App\Services\ProduccionService;
use App\Services\TrazabilidadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Escenario completo de la cadena:
 *
 *   Proveedores -> lotes de insumo -> OP1 (250 panes) y OP2 (100 panes)
 *               -> despacho 1: 150 panes a "Supermercado El Prado"  (todo de OP1)
 *               -> despacho 2: 120 panes a "Tienda Doña Rosa"       (100 de OP1 + 20 de OP2)
 *
 *   Leche B (3 lt, vence antes) solo se usa en OP1.
 *   Leche A se usa en OP1 (2 lt) y OP2 (2 lt).
 */
class TrazabilidadServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrazabilidadService $trazabilidad;

    private Proveedor $molino;
    private Proveedor $lecheria;

    private Lote $loteHarina;
    private Lote $loteLecheA;
    private Lote $loteLecheB;
    private Lote $loteAzucar;

    private OrdenProduccion $orden1;
    private OrdenProduccion $orden2;
    private Despacho $despacho1;
    private Despacho $despacho2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trazabilidad = app(TrazabilidadService::class);
        $lotes = app(LoteService::class);

        $operador = User::create([
            'name' => 'Operador Test',
            'email' => 'operador.test@sigesta.com',
            'password' => 'password',
            'role' => User::ROLE_OPERADOR,
        ]);

        $this->molino = Proveedor::create(['codigo_proveedor' => 'PRV-001', 'razon_social' => 'Molinos Andinos S.R.L.', 'nit' => '3001001']);
        $this->lecheria = Proveedor::create(['codigo_proveedor' => 'PRV-002', 'razon_social' => 'Lácteos del Valle', 'nit' => '3002002']);

        $harina = Insumo::create(['codigo' => 'INS-HAR', 'nombre' => 'Harina de trigo', 'unidad_medida' => 'kg']);
        $leche = Insumo::create(['codigo' => 'INS-LEC', 'nombre' => 'Leche entera', 'unidad_medida' => 'lt']);
        $azucar = Insumo::create(['codigo' => 'INS-AZU', 'nombre' => 'Azúcar blanca', 'unidad_medida' => 'kg']);

        $pan = Producto::create(['codigo' => 'PRD-PAN', 'nombre' => 'Pan de leche', 'unidad_medida' => 'unidad', 'dias_vida_util' => 5]);

        $receta = Receta::create(['producto_id' => $pan->id, 'nombre_receta' => 'Pan de leche estándar', 'rendimiento_base' => 100]);
        $receta->insumos()->attach([
            $harina->id => ['cantidad_requerida' => 5],
            $leche->id => ['cantidad_requerida' => 2],
            $azucar->id => ['cantidad_requerida' => 0.8],
        ]);

        $this->loteHarina = $this->crearLoteInsumo($harina, 'HAR-001', 90, 50, $this->molino);
        $this->loteLecheA = $this->crearLoteInsumo($leche, 'LEC-A', 20, 40, $this->lecheria);
        $this->loteLecheB = $this->crearLoteInsumo($leche, 'LEC-B', 10, 3, $this->lecheria);
        $this->loteAzucar = $this->crearLoteInsumo($azucar, 'AZU-001', 180, 10, null);
        foreach ([$harina, $leche, $azucar] as $insumo) {
            $lotes->recalcularStockInsumo($insumo);
        }

        $clientePrado = Cliente::create(['codigo_cliente' => 'CLI-001', 'razon_social' => 'Supermercado El Prado', 'nit_ci' => '1023456789']);
        $clienteRosa = Cliente::create(['codigo_cliente' => 'CLI-002', 'razon_social' => 'Tienda Doña Rosa', 'nit_ci' => '5544332']);

        $produccion = app(ProduccionService::class);
        $this->orden1 = $produccion->ejecutarOrdenProduccion($pan->id, 250, $operador->id);
        $this->orden2 = $produccion->ejecutarOrdenProduccion($pan->id, 100, $operador->id);

        $despachos = app(DespachoService::class);
        $this->despacho1 = $despachos->registrarDespacho($clientePrado->id, [['producto_id' => $pan->id, 'cantidad' => 150]], $operador->id);
        $this->despacho2 = $despachos->registrarDespacho($clienteRosa->id, [['producto_id' => $pan->id, 'cantidad' => 120]], $operador->id);
    }

    public function test_trazabilidad_completa_desde_lote_de_materia_prima_hasta_el_cliente_final(): void
    {
        $reporte = $this->trazabilidad->rastrearInsumoHaciaAdelante($this->loteLecheA->id);

        // Origen
        $this->assertSame('LEC-A', $reporte['lote_insumo']['codigo_lote']);
        $this->assertSame('Leche entera', $reporte['lote_insumo']['insumo']['nombre']);
        $this->assertSame('Lácteos del Valle', $reporte['lote_insumo']['proveedor']['razon_social']);

        // Producción: usado en ambas órdenes
        $this->assertSame(
            [$this->orden1->codigo_orden, $this->orden2->codigo_orden],
            array_column(array_column($reporte['producciones'], 'orden_produccion'), 'codigo_orden'),
        );
        $this->assertSame(['2.000', '2.000'], array_column($reporte['producciones'], 'cantidad_insumo_consumida'));
        $this->assertSame(
            [$this->orden1->loteProducto->codigo_lote, $this->orden2->loteProducto->codigo_lote],
            array_column(array_column($reporte['producciones'], 'lote_producto'), 'codigo_lote'),
        );

        // Clientes finales
        $clientes = collect($reporte['clientes_afectados'])->keyBy('razon_social');
        $this->assertSame(['Supermercado El Prado', 'Tienda Doña Rosa'], $clientes->keys()->all());

        $entregasPrado = $clientes['Supermercado El Prado']['entregas'];
        $this->assertCount(1, $entregasPrado);
        $this->assertSame($this->despacho1->codigo_despacho, $entregasPrado[0]['codigo_despacho']);
        $this->assertSame('150.000', $entregasPrado[0]['cantidad']);

        // Doña Rosa recibió panes de los dos lotes en un mismo despacho
        $entregasRosa = $clientes['Tienda Doña Rosa']['entregas'];
        $this->assertSame(['100.000', '20.000'], array_column($entregasRosa, 'cantidad'));
        $this->assertSame(
            [$this->orden1->loteProducto->codigo_lote, $this->orden2->loteProducto->codigo_lote],
            array_column($entregasRosa, 'lote_producto'),
        );

        $this->assertSame([
            'ordenes_produccion' => 2,
            'lotes_producto' => 2,
            'despachos' => 2,
            'clientes_afectados' => 2,
        ], $reporte['resumen']);
    }

    public function test_un_lote_usado_en_una_sola_orden_solo_alcanza_a_los_clientes_de_esa_orden(): void
    {
        $reporte = $this->trazabilidad->rastrearInsumoHaciaAdelante($this->loteLecheB->id);

        $this->assertCount(1, $reporte['producciones']);
        $this->assertSame('3.000', $reporte['producciones'][0]['cantidad_insumo_consumida']);

        // OP1 llegó a ambos clientes: 150 al Prado y 100 a Doña Rosa
        $cantidades = collect($reporte['clientes_afectados'])
            ->mapWithKeys(fn ($c) => [$c['razon_social'] => array_column($c['entregas'], 'cantidad')])
            ->all();

        $this->assertSame([
            'Supermercado El Prado' => ['150.000'],
            'Tienda Doña Rosa' => ['100.000'],
        ], $cantidades);
    }

    public function test_un_lote_de_insumo_sin_uso_no_tiene_alcance(): void
    {
        $leche = Insumo::where('codigo', 'INS-LEC')->first();
        $loteNuevo = $this->crearLoteInsumo($leche, 'LEC-C', 30, 20, $this->lecheria);

        $reporte = $this->trazabilidad->rastrearInsumoHaciaAdelante($loteNuevo->id);

        $this->assertSame([], $reporte['producciones']);
        $this->assertSame([], $reporte['clientes_afectados']);
        $this->assertSame(0, $reporte['resumen']['clientes_afectados']);
    }

    public function test_rastrea_producto_hacia_atras_con_receta_insumos_y_proveedores(): void
    {
        $loteProducto = $this->orden2->loteProducto;

        $reporte = $this->trazabilidad->rastrearProductoHaciaAtras($loteProducto->id);

        $this->assertSame($loteProducto->codigo_lote, $reporte['lote_producto']['codigo_lote']);
        $this->assertSame('Pan de leche', $reporte['lote_producto']['producto']['nombre']);

        $this->assertSame($this->orden2->codigo_orden, $reporte['orden_produccion']['codigo_orden']);
        $this->assertSame('Operador Test', $reporte['orden_produccion']['responsable']);

        $this->assertSame('Pan de leche estándar', $reporte['receta']['nombre_receta']);
        $this->assertSame(['Harina de trigo', 'Leche entera', 'Azúcar blanca'], array_column($reporte['receta']['formula'], 'insumo'));

        // OP2 (100 panes): harina 5 kg, leche 2 lt (de A, porque B ya se agotó en OP1), azúcar 0.8 kg
        $origen = $reporte['insumos_origen'];
        $this->assertSame(['HAR-001', 'LEC-A', 'AZU-001'], array_column(array_column($origen, 'lote'), 'codigo_lote'));
        $this->assertSame(['5.000', '2.000', '0.800'], array_column($origen, 'cantidad_consumida'));

        $this->assertSame('Molinos Andinos S.R.L.', $origen[0]['proveedor']['razon_social']);
        $this->assertSame('Lácteos del Valle', $origen[1]['proveedor']['razon_social']);
        $this->assertNull($origen[2]['proveedor']);

        $this->assertSame(
            $this->loteLecheA->fecha_vencimiento->toDateString(),
            $origen[1]['lote']['fecha_vencimiento'],
        );
    }

    public function test_valida_el_tipo_de_lote_en_cada_direccion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->trazabilidad->rastrearInsumoHaciaAdelante($this->orden1->lote_producto_id);
    }

    public function test_rechaza_rastrear_hacia_atras_un_lote_de_insumo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->trazabilidad->rastrearProductoHaciaAtras($this->loteHarina->id);
    }

    // ---------- Helpers ----------

    private function crearLoteInsumo(Insumo $insumo, string $codigo, int $diasParaVencer, float $cantidad, ?Proveedor $proveedor): Lote
    {
        return Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => Lote::TIPO_INSUMO,
            'insumo_id' => $insumo->id,
            'proveedor_id' => $proveedor?->id,
            'fecha_fabricacion' => now()->subDay()->toDateString(),
            'fecha_vencimiento' => now()->addDays($diasParaVencer)->toDateString(),
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => Lote::ESTADO_ACTIVO,
        ]);
    }
}
