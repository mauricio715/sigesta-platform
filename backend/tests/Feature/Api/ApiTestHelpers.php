<?php

namespace Tests\Feature\Api;

use App\Models\Insumo;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\User;
use App\Services\LoteService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Utilidades compartidas por las pruebas de la API.
 */
trait ApiTestHelpers
{
    protected function crearUsuario(
        string $rol = User::ROLE_OPERADOR,
        string $estado = User::STATUS_ACTIVE,
    ): User {
        return User::create([
            'name' => ucfirst($rol) . ' Test',
            'email' => $rol . '.' . Str::lower(Str::random(8)) . '@sigesta.test',
            'password' => 'password',
            'role' => $rol,
            'status' => $estado,
        ]);
    }

    /** Autentica las siguientes peticiones con Sanctum */
    protected function autenticarComo(string $rol = User::ROLE_OPERADOR): User
    {
        $user = $this->crearUsuario($rol);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Escenario base del pan de leche:
     *   BOM 100 panes = 5 kg harina + 2 lt leche + 0.8 kg azúcar
     *   Leche A (40 lt, vence en 20 días) y Leche B (3 lt, vence en 10 días)
     *
     * @return array<string, mixed>
     */
    protected function crearEscenarioProduccion(): array
    {
        $harina = Insumo::create(['codigo' => 'INS-HAR', 'nombre' => 'Harina de trigo', 'unidad_medida' => 'kg']);
        $leche = Insumo::create(['codigo' => 'INS-LEC', 'nombre' => 'Leche entera', 'unidad_medida' => 'lt']);
        $azucar = Insumo::create(['codigo' => 'INS-AZU', 'nombre' => 'Azúcar blanca', 'unidad_medida' => 'kg']);

        $pan = Producto::create([
            'codigo' => 'PRD-PAN',
            'nombre' => 'Pan de leche',
            'unidad_medida' => 'unidad',
            'dias_vida_util' => 5,
        ]);

        $receta = Receta::create([
            'producto_id' => $pan->id,
            'nombre_receta' => 'Pan de leche estándar',
            'rendimiento_base' => 100,
        ]);
        $receta->insumos()->attach([
            $harina->id => ['cantidad_requerida' => 5],
            $leche->id => ['cantidad_requerida' => 2],
            $azucar->id => ['cantidad_requerida' => 0.8],
        ]);

        return [
            'harina' => $harina,
            'leche' => $leche,
            'azucar' => $azucar,
            'pan' => $pan,
            'receta' => $receta,
            'loteHarina' => $this->crearLoteInsumo($harina, 'HAR-001', 90, 50),
            'loteLecheA' => $this->crearLoteInsumo($leche, 'LEC-A', 20, 40),
            'loteLecheB' => $this->crearLoteInsumo($leche, 'LEC-B', 10, 3),
            'loteAzucar' => $this->crearLoteInsumo($azucar, 'AZU-001', 180, 10),
        ];
    }

    protected function crearLoteInsumo(Insumo $insumo, string $codigo, int $diasParaVencer, float $cantidad, int $diasDesdeFabricacion = 1): Lote
    {
        $lote = Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => Lote::TIPO_INSUMO,
            'insumo_id' => $insumo->id,
            'fecha_fabricacion' => now()->subDays($diasDesdeFabricacion)->toDateString(),
            'fecha_vencimiento' => now()->addDays($diasParaVencer)->toDateString(),
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => Lote::ESTADO_ACTIVO,
        ]);

        app(LoteService::class)->recalcularStockInsumo($insumo);

        return $lote;
    }

    protected function crearLoteProducto(Producto $producto, string $codigo, int $diasParaVencer, float $cantidad): Lote
    {
        $lote = Lote::create([
            'codigo_lote' => $codigo,
            'tipo_item' => Lote::TIPO_PRODUCTO,
            'producto_id' => $producto->id,
            'fecha_fabricacion' => now()->subDay()->toDateString(),
            'fecha_vencimiento' => now()->addDays($diasParaVencer)->toDateString(),
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'estado' => Lote::ESTADO_ACTIVO,
        ]);

        app(LoteService::class)->recalcularStockProducto($producto);

        return $lote;
    }
}
