<?php

namespace Tests\Unit;

use App\Models\Insumo;
use App\Models\Lote;
use App\Models\User;
use App\Services\LoteService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas unitarias del motor FEFO (First Expired, First Out)
 * Unidad bajo prueba: LoteService::descontarStockInsumo()
 */
class ConsumoFefoTest extends TestCase
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
            'name' => 'Operador Prueba',
            'email' => 'operador.prueba@sigesta.com',
            'password' => 'password',
            'role' => User::ROLE_OPERADOR,
        ]);

        $this->leche = Insumo::create([
            'codigo' => 'INS-LEC-UT',
            'nombre' => 'Leche entera',
            'unidad_medida' => 'lt',
            'stock_minimo' => 10,
            'stock_actual' => 0,
        ]);
    }

    // =====================================================================
    // PRUEBA 1: Prioridad FEFO
    // =====================================================================
    public function test_consume_primero_el_lote_que_vence_antes(): void
    {
        // ARRANGE: Lote A (vence en 20 días, 40 lt) | Lote B (vence en 10 días, 30 lt)
        $loteA = $this->crearLote('LEC-A', diasParaVencer: 20, cantidad: 40);
        $loteB = $this->crearLote('LEC-B', diasParaVencer: 10, cantidad: 30);

        // ACT: Pedimos 35 lt para producción
        $this->service->descontarStockInsumo(
            $this->leche->id,
            35,
            $this->operador->id,
            'Orden de prueba'
        );

        // ASSERT: Se vacía primero B (vence antes) y A cubre los 5 lt restantes
        $loteA->refresh();
        $loteB->refresh();

        $this->assertSame('0.000', $loteB->cantidad_actual);
        $this->assertSame(Lote::ESTADO_AGOTADO, $loteB->estado);

        $this->assertSame('35.000', $loteA->cantidad_actual);
        $this->assertSame(Lote::ESTADO_ACTIVO, $loteA->estado);

        $this->assertSame('35.000', $this->leche->fresh()->stock_actual);
    }

    // =====================================================================
    // PRUEBA 2: Control de Stock Insuficiente
    // =====================================================================
    public function test_falla_si_el_stock_es_insuficiente(): void
    {
        // ARRANGE: Solo tenemos un lote con 20 lt
        $this->crearLote('LEC-A', diasParaVencer: 10, cantidad: 20);

        // ASSERT: Esperamos que la función lance una excepción por falta de stock
        $this->expectException(Exception::class);

        // ACT: Intentamos consumir 50 lt (más de lo existente)
        $this->service->descontarStockInsumo(
            $this->leche->id,
            50,
            $this->operador->id,
            'Orden de prueba fallida'
        );
    }

    // =====================================================================
    // PRUEBA 3: Inocuidad (Ignorar lotes vencidos)
    // =====================================================================
    public function test_ignora_lotes_vencidos(): void
    {
        // ARRANGE: Creamos un lote que venció HACE 2 DÍAS (-2)
        $this->crearLote('LEC-VENCIDO', diasParaVencer: -2, cantidad: 50);

        // ASSERT: Debe lanzar un error porque no hay stock VÁLIDO disponible
        $this->expectException(Exception::class);

        // ACT: Intentar consumir del inventario
        $this->service->descontarStockInsumo(
            $this->leche->id,
            10,
            $this->operador->id,
            'Intento de consumo vencido'
        );
    }

    // =====================================================================
    // Helper para la creación de lotes respetando restricciones SQL
    // =====================================================================
    private function crearLote(string $codigo, int $diasParaVencer, float $cantidad): Lote
    {
        // La fecha de fabricación siempre debe ser anterior a la fecha de vencimiento
        $fechaVencimiento = now()->addDays($diasParaVencer)->toDateString();
        $fechaFabricacion = now()->addDays($diasParaVencer - 5)->toDateString(); // Fabricado 5 días antes de vencer

        $lote = Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => Lote::TIPO_INSUMO,
            'insumo_id' => $this->leche->id,
            'fecha_fabricacion' => $fechaFabricacion,
            'fecha_vencimiento' => $fechaVencimiento,
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => Lote::ESTADO_ACTIVO,
        ]);

        $this->service->recalcularStockInsumo($this->leche);

        return $lote;
    }
}