<?php

namespace Tests\Feature\Api;

use App\Models\Alerta;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MermaApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private Insumo $leche;
    private Lote $lote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leche = Insumo::create([
            'codigo' => 'INS-LEC',
            'nombre' => 'Leche entera',
            'unidad_medida' => 'lt',
            'stock_minimo' => 10,
        ]);

        $this->lote = $this->crearLoteInsumo($this->leche, 'LEC-01', 10, 20);
    }

    public function test_registra_una_merma_parcial(): void
    {
        $operador = $this->autenticarComo();

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $this->lote->id,
            'cantidad' => 5,
            'motivo' => 'DETERIORO',
            'observacion' => 'Envases inflados por cadena de frío interrumpida',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.tipo_movimiento', MovimientoInventario::MERMA_DESECHO)
            ->assertJsonPath('data.es_entrada', false)
            ->assertJsonPath('data.categoria_merma', 'DETERIORO')
            ->assertJsonPath('data.cantidad', '5.000')
            ->assertJsonPath('data.responsable.id', $operador->id)
            ->assertJsonPath('data.lote.cantidad_actual', '15.000')
            ->assertJsonPath('data.lote.estado', Lote::ESTADO_ACTIVO)
            ->assertJsonPath('data.lote.insumo.stock_actual', '15.000');

        $this->assertDatabaseHas('movimientos_inventario', [
            'lote_id' => $this->lote->id,
            'tipo_movimiento' => MovimientoInventario::MERMA_DESECHO,
            'motivo_observacion' => 'Envases inflados por cadena de frío interrumpida',
        ]);
    }

    public function test_merma_total_agota_el_lote_resuelve_sus_alertas_y_dispara_minimo(): void
    {
        $this->autenticarComo();

        Alerta::create([
            'lote_id' => $this->lote->id,
            'insumo_id' => $this->leche->id,
            'tipo_alerta' => Alerta::PREVENTIVA_VENCIMIENTO,
            'nivel_prioridad' => Alerta::PRIORIDAD_MEDIA,
            'mensaje' => 'Lote LEC-01 próximo a vencer.',
        ]);

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $this->lote->id,
            'cantidad' => 20,
            'motivo' => 'CONTAMINACION',
        ])
            ->assertCreated()
            ->assertJsonPath('data.lote.estado', Lote::ESTADO_AGOTADO)
            ->assertJsonPath('data.motivo_observacion', 'Baja por contaminacion');

        $this->assertTrue(
            Alerta::where('tipo_alerta', Alerta::PREVENTIVA_VENCIMIENTO)->first()->leida,
            'La alerta del lote dado de baja debe quedar atendida',
        );

        // 0 lt < mínimo de 10 lt => alerta de stock mínimo
        $this->assertSame('0.000', $this->leche->fresh()->stock_actual);
        $this->assertDatabaseHas('alertas', [
            'insumo_id' => $this->leche->id,
            'tipo_alerta' => Alerta::STOCK_MINIMO,
            'leida' => false,
        ]);
    }

    public function test_rechaza_cantidades_mayores_al_saldo_del_lote(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $this->lote->id,
            'cantidad' => 25,
            'motivo' => 'DETERIORO',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'La cantidad a dar de baja (25.000) supera el saldo del lote LEC-01 (20.000).');

        $this->assertSame('20.000', $this->lote->fresh()->cantidad_actual);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_rechaza_bajas_sobre_un_lote_agotado(): void
    {
        $this->autenticarComo();
        $this->lote->update(['cantidad_actual' => 0, 'estado' => Lote::ESTADO_AGOTADO]);

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $this->lote->id,
            'cantidad' => 1,
            'motivo' => 'DETERIORO',
        ])->assertUnprocessable()->assertJsonPath('message', 'El lote LEC-01 no tiene saldo disponible.');
    }

    public function test_baja_por_vencimiento_solo_procede_en_lotes_vencidos(): void
    {
        $this->autenticarComo();

        // Lote vigente: se rechaza
        $this->postJson('/api/v1/mermas', [
            'lote_id' => $this->lote->id,
            'cantidad' => 5,
            'motivo' => 'VENCIMIENTO',
        ])->assertUnprocessable();

        // Lote vencido (marcado VENCIDO por el motor de alertas): procede
        $vencido = $this->crearLoteInsumo($this->leche, 'LEC-VENC', -2, 8, diasDesdeFabricacion: 15);
        $vencido->update(['estado' => Lote::ESTADO_VENCIDO]);

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $vencido->id,
            'cantidad' => 8,
            'motivo' => 'VENCIMIENTO',
        ])
            ->assertCreated()
            ->assertJsonPath('data.tipo_movimiento', MovimientoInventario::BAJA_VENCIMIENTO)
            ->assertJsonPath('data.lote.estado', Lote::ESTADO_AGOTADO);

        // El stock utilizable no cambia: el lote vencido ya no contaba
        $this->assertSame('20.000', $this->leche->fresh()->stock_actual);
    }

    public function test_da_de_baja_lotes_de_producto_terminado(): void
    {
        $this->autenticarComo();
        $pan = Producto::create(['codigo' => 'PRD-PAN', 'nombre' => 'Pan de leche', 'unidad_medida' => 'unidad', 'dias_vida_util' => 5]);
        $lotePan = $this->crearLoteProducto($pan, 'PAN-01', 4, 200);

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $lotePan->id,
            'cantidad' => 12,
            'motivo' => 'DANO_EMPAQUE',
        ])
            ->assertCreated()
            ->assertJsonPath('data.lote.producto.stock_actual', '188.000');
    }

    public function test_valida_motivos_permitidos_y_observacion_obligatoria_para_otro(): void
    {
        $this->autenticarComo();

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $this->lote->id,
            'cantidad' => 1,
            'motivo' => 'ME_LO_COMI',
        ])->assertUnprocessable()->assertJsonValidationErrors(['motivo']);

        $this->postJson('/api/v1/mermas', [
            'lote_id' => $this->lote->id,
            'cantidad' => 1,
            'motivo' => 'OTRO',
        ])->assertUnprocessable()->assertJsonValidationErrors(['observacion']);

        $this->postJson('/api/v1/mermas', [
            'lote_id' => 99999,
            'cantidad' => -1,
            'motivo' => 'DETERIORO',
        ])->assertUnprocessable()->assertJsonValidationErrors(['lote_id', 'cantidad']);
    }
}
