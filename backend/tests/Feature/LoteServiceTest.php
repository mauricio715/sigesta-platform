<?php

namespace Tests\Feature;

use App\Exceptions\StockInsuficienteException;
use App\Models\Alerta;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\LoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class LoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoteService $service;
    private User $operador;
    private Insumo $leche;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LoteService::class);

        $this->operador = User::create([
            'name' => 'Operador Test',
            'email' => 'operador.test@sigesta.com',
            'password' => 'password',
            'role' => User::ROLE_OPERADOR,
        ]);

        $this->leche = Insumo::create([
            'codigo' => 'INS-LEC-T01',
            'nombre' => 'Leche entera',
            'unidad_medida' => 'lt',
            'stock_minimo' => 50,
            'stock_actual' => 0,
        ]);
    }

    // =====================================================================
    //  Pruebas
    // =====================================================================

    public function test_descuenta_lotes_siguiendo_estrictamente_criterio_fefo(): void
    {
        [$loteA, $loteB] = $this->escenarioFefo();

        // Precondición: A ingresó antes que B (FIFO lo elegiría primero)
        $this->assertLessThan($loteB->id, $loteA->id);

        // 35 lt: B (vence en 10 días) debe agotarse y los 5 restantes salir de A
        $this->service->descontarStockInsumo($this->leche->id, 35, $this->operador->id, 'Orden de producción de prueba');

        $loteA->refresh();
        $loteB->refresh();

        $this->assertSame('0.000', $loteB->cantidad_actual);
        $this->assertSame(Lote::ESTADO_AGOTADO, $loteB->estado);

        $this->assertSame('35.000', $loteA->cantidad_actual);
        $this->assertSame(Lote::ESTADO_ACTIVO, $loteA->estado);

        $this->assertSame('35.000', $this->leche->fresh()->stock_actual);
    }

    public function test_consumo_parcial_no_toca_el_lote_de_vencimiento_posterior(): void
    {
        [$loteA, $loteB] = $this->escenarioFefo();

        $this->service->descontarStockInsumo($this->leche->id, 12.5, $this->operador->id, 'Consumo parcial');

        $this->assertSame('17.500', $loteB->fresh()->cantidad_actual);
        $this->assertSame('40.000', $loteA->fresh()->cantidad_actual);
    }

    public function test_lanza_excepcion_si_el_stock_es_insuficiente(): void
    {
        [$loteA, $loteB] = $this->escenarioFefo(); // 70 lt en total

        try {
            $this->service->descontarStockInsumo($this->leche->id, 100, $this->operador->id, 'Pedido excesivo');
            $this->fail('Se esperaba StockInsuficienteException.');
        } catch (StockInsuficienteException $e) {
            $this->assertStringContainsString('Stock insuficiente', $e->getMessage());
            $this->assertSame('100.000', $e->cantidadRequerida);
            $this->assertSame('70.000', $e->cantidadDisponible);
        }

        // Atomicidad: nada se modificó
        $this->assertSame('40.000', $loteA->fresh()->cantidad_actual);
        $this->assertSame('30.000', $loteB->fresh()->cantidad_actual);
        $this->assertSame('70.000', $this->leche->fresh()->stock_actual);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_rechaza_cantidades_no_positivas(): void
    {
        $this->escenarioFefo();

        $this->expectException(InvalidArgumentException::class);

        $this->service->descontarStockInsumo($this->leche->id, 0, $this->operador->id, 'Cantidad inválida');
    }

    public function test_ignora_lotes_vencidos_aunque_su_estado_no_se_haya_actualizado(): void
    {
        // Lote vencido hace 2 días pero todavía marcado ACTIVO (el job de alertas aún no corrió)
        $vencido = $this->crearLote('LEC-T-VENC', diasParaVencer: -2, cantidad: 100, diasDesdeFabricacion: 15);
        $vigente = $this->crearLote('LEC-T-VIG', diasParaVencer: 10, cantidad: 30);

        $this->service->descontarStockInsumo($this->leche->id, 20, $this->operador->id, 'Consumo con lote vencido presente');

        $this->assertSame('100.000', $vencido->fresh()->cantidad_actual);
        $this->assertSame('10.000', $vigente->fresh()->cantidad_actual);

        // El vencido tampoco cuenta como stock disponible
        $this->expectException(StockInsuficienteException::class);
        $this->service->descontarStockInsumo($this->leche->id, 50, $this->operador->id, 'Excede el stock vigente');
    }

    public function test_registra_correctamente_el_kardex_inmutable(): void
    {
        [$loteA, $loteB] = $this->escenarioFefo();

        $movimientos = $this->service->descontarStockInsumo(
            $this->leche->id, 35, $this->operador->id, 'OP-0001 Pan de leche'
        );

        // Un movimiento por cada lote afectado
        $this->assertCount(2, $movimientos);
        $this->assertDatabaseCount('movimientos_inventario', 2);

        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo_movimiento' => MovimientoInventario::CONSUMO_PRODUCCION,
            'lote_id' => $loteB->id,
            'cantidad' => '30.000',
            'user_id' => $this->operador->id,
            'motivo_observacion' => 'OP-0001 Pan de leche',
        ]);

        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo_movimiento' => MovimientoInventario::CONSUMO_PRODUCCION,
            'lote_id' => $loteA->id,
            'cantidad' => '5.000',
            'user_id' => $this->operador->id,
        ]);

        // El orden del Kardex refleja el orden FEFO
        $this->assertSame($loteB->id, $movimientos[0]->lote_id);
        $this->assertSame($loteA->id, $movimientos[1]->lote_id);

        // Inmutabilidad: editar un movimiento debe fallar
        $this->expectException(LogicException::class);
        $movimientos[0]->update(['cantidad' => 1]);
    }

    public function test_un_movimiento_del_kardex_no_puede_eliminarse(): void
    {
        $this->escenarioFefo();

        $movimientos = $this->service->descontarStockInsumo($this->leche->id, 5, $this->operador->id, 'Consumo');

        $this->expectException(LogicException::class);
        $movimientos->first()->delete();
    }

    public function test_genera_alerta_de_stock_minimo_cuando_corresponda(): void
    {
        $this->escenarioFefo(); // 70 lt, mínimo 50

        // 70 -> 40: bajo el mínimo pero sobre la mitad => MEDIA
        $this->service->descontarStockInsumo($this->leche->id, 30, $this->operador->id, 'Consumo 1');

        $alerta = Alerta::where('insumo_id', $this->leche->id)
            ->where('tipo_alerta', Alerta::STOCK_MINIMO)
            ->first();

        $this->assertNotNull($alerta);
        $this->assertFalse($alerta->leida);
        $this->assertSame(Alerta::PRIORIDAD_MEDIA, $alerta->nivel_prioridad);
        $this->assertStringContainsString('40.000 lt', $alerta->mensaje);

        // 40 -> 20: en la mitad del mínimo o menos => ALTA, misma alerta actualizada
        $this->service->descontarStockInsumo($this->leche->id, 20, $this->operador->id, 'Consumo 2');

        $this->assertSame(
            1,
            Alerta::where('insumo_id', $this->leche->id)->where('tipo_alerta', Alerta::STOCK_MINIMO)->count(),
            'Debe existir una sola alerta abierta por insumo.'
        );
        $this->assertSame(Alerta::PRIORIDAD_ALTA, $alerta->fresh()->nivel_prioridad);
        $this->assertStringContainsString('20.000 lt', $alerta->fresh()->mensaje);
    }

    public function test_no_genera_alerta_si_el_stock_sigue_sobre_el_minimo(): void
    {
        $this->escenarioFefo(); // 70 lt, mínimo 50

        $this->service->descontarStockInsumo($this->leche->id, 15, $this->operador->id, 'Consumo menor'); // queda 55

        $this->assertDatabaseCount('alertas', 0);
    }

    // =====================================================================
    //  Helpers
    // =====================================================================

    /**
     * Lote A: ingresó primero, vence en 20 días, 40 lt.
     * Lote B: ingresó después, vence en 10 días, 30 lt.
     *
     * @return array{0: Lote, 1: Lote}
     */
    private function escenarioFefo(): array
    {
        $loteA = $this->crearLote('LEC-T-A', diasParaVencer: 20, cantidad: 40, diasDesdeFabricacion: 3);
        $loteB = $this->crearLote(
            'LEC-T-B',
            diasParaVencer: 10,
            cantidad: 30,
            diasDesdeFabricacion: 1,
            estado: Lote::ESTADO_PROXIMO_A_VENCER,
        );

        return [$loteA, $loteB];
    }

    private function crearLote(
        string $codigo,
        int $diasParaVencer,
        float $cantidad,
        int $diasDesdeFabricacion = 1,
        string $estado = Lote::ESTADO_ACTIVO,
    ): Lote {
        $lote = Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => Lote::TIPO_INSUMO,
            'insumo_id' => $this->leche->id,
            'fecha_fabricacion' => now()->subDays($diasDesdeFabricacion)->toDateString(),
            'fecha_vencimiento' => now()->addDays($diasParaVencer)->toDateString(),
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => $estado,
        ]);

        // Mantiene insumos.stock_actual coherente con los lotes creados
        $this->service->recalcularStockInsumo($this->leche);

        return $lote;
    }
}
