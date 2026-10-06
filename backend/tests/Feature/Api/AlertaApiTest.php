<?php

namespace Tests\Feature\Api;

use App\Models\Alerta;
use App\Models\Insumo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    private Insumo $leche;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leche = Insumo::create(['codigo' => 'INS-LEC', 'nombre' => 'Leche entera', 'unidad_medida' => 'lt']);

        $this->crearAlerta(Alerta::STOCK_MINIMO, Alerta::PRIORIDAD_MEDIA, leida: false);
        $this->crearAlerta(Alerta::VENCIMIENTO_CRITICO, Alerta::PRIORIDAD_ALTA, leida: false);
        $this->crearAlerta(Alerta::PREVENTIVA_VENCIMIENTO, Alerta::PRIORIDAD_BAJA, leida: true);
    }

    public function test_por_defecto_lista_pendientes_ordenadas_por_criticidad(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/alertas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nivel_prioridad', 'ALTA')
            ->assertJsonPath('data.1.nivel_prioridad', 'MEDIA')
            ->assertJsonPath('data.0.item.nombre', 'Leche entera');
    }

    public function test_filtra_por_estado_y_criticidad(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/alertas?estado=todas')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/alertas?estado=leidas')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/alertas?estado=todas&nivel_prioridad=ALTA')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo_alerta', Alerta::VENCIMIENTO_CRITICO);
    }

    public function test_rechaza_filtros_invalidos(): void
    {
        $this->autenticarComo();

        $this->getJson('/api/v1/alertas?nivel_prioridad=URGENTE&estado=archivadas')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nivel_prioridad', 'estado']);
    }

    public function test_marca_una_alerta_como_leida(): void
    {
        $this->autenticarComo();
        $alerta = Alerta::where('nivel_prioridad', Alerta::PRIORIDAD_ALTA)->first();

        $this->patchJson("/api/v1/alertas/{$alerta->id}/leer")
            ->assertOk()
            ->assertJsonPath('data.leida', true);

        $this->getJson('/api/v1/alertas')->assertJsonCount(1, 'data');
    }

    private function crearAlerta(string $tipo, string $prioridad, bool $leida): Alerta
    {
        return Alerta::create([
            'insumo_id' => $this->leche->id,
            'tipo_alerta' => $tipo,
            'nivel_prioridad' => $prioridad,
            'mensaje' => "Alerta de prueba {$tipo}",
            'leida' => $leida,
        ]);
    }
}
