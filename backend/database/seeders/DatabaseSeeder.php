<?php

namespace Database\Seeders;

use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // ---------- Usuarios ----------
            // El cast 'hashed' del modelo User aplica Bcrypt automáticamente
            $admin = User::create([
                'name' => 'Administrador SI-GESTA',
                'email' => 'admin@sigesta.com',
                'password' => 'password',
                'role' => User::ROLE_ADMIN,
                'status' => User::STATUS_ACTIVE,
            ]);

            User::create([
                'name' => 'Operador SI-GESTA',
                'email' => 'operador@sigesta.com',
                'password' => 'password',
                'role' => User::ROLE_OPERADOR,
                'status' => User::STATUS_ACTIVE,
            ]);

            // ---------- Insumos ----------
            $harina = Insumo::create([
                'codigo' => 'INS-HAR-001',
                'nombre' => 'Harina de trigo',
                'descripcion' => 'Harina de trigo panadera 000',
                'unidad_medida' => 'kg',
                'stock_minimo' => 50,
                'stock_actual' => 0,
            ]);

            $leche = Insumo::create([
                'codigo' => 'INS-LEC-001',
                'nombre' => 'Leche entera',
                'descripcion' => 'Leche entera pasteurizada',
                'unidad_medida' => 'lt',
                'stock_minimo' => 20,
                'stock_actual' => 0, // se actualiza al registrar los lotes
            ]);

            $azucar = Insumo::create([
                'codigo' => 'INS-AZU-001',
                'nombre' => 'Azúcar blanca',
                'descripcion' => 'Azúcar refinada',
                'unidad_medida' => 'kg',
                'stock_minimo' => 25,
                'stock_actual' => 0,
            ]);

            // ---------- Producto terminado + Receta BOM ----------
            $panLeche = Producto::create([
                'codigo' => 'PRD-PAN-001',
                'nombre' => 'Pan de leche',
                'descripcion' => 'Pan de leche en unidades de 60 g',
                'unidad_medida' => 'unidad',
                'dias_vida_util' => 5,
                'stock_minimo' => 100,
                'stock_actual' => 0,
            ]);

            $receta = Receta::create([
                'producto_id' => $panLeche->id,
                'nombre_receta' => 'Pan de leche - fórmula estándar',
                'rendimiento_base' => 100, // 100 unidades por tanda
                'observaciones' => 'Fórmula base para 100 unidades de 60 g.',
            ]);

            $receta->insumos()->attach([
                $harina->id => ['cantidad_requerida' => 5.000],
                $leche->id => ['cantidad_requerida' => 2.000],
                $azucar->id => ['cantidad_requerida' => 0.800],
            ]);

            // ---------- Lotes de insumo para probar FEFO ----------
            // Escenario: el lote A llegó PRIMERO pero vence DESPUÉS;
            // el lote B llegó después pero vence antes.
            // FIFO elegiría A; FEFO debe elegir B.
            $loteA = Lote::create([
                'codigo_lote' => 'LEC-' . now()->subDays(3)->format('Ymd') . '-01',
                'tipo_item' => Lote::TIPO_INSUMO,
                'insumo_id' => $leche->id,
                'fecha_fabricacion' => now()->subDays(3)->toDateString(),
                'fecha_vencimiento' => now()->addDays(20)->toDateString(),
                'cantidad_inicial' => 40,
                'cantidad_actual' => 40,
                'estado' => Lote::ESTADO_ACTIVO,
            ]);

            $loteB = Lote::create([
                'codigo_lote' => 'LEC-' . now()->subDay()->format('Ymd') . '-02',
                'tipo_item' => Lote::TIPO_INSUMO,
                'insumo_id' => $leche->id,
                'fecha_fabricacion' => now()->subDay()->toDateString(),
                'fecha_vencimiento' => now()->addDays(10)->toDateString(),
                'cantidad_inicial' => 30,
                'cantidad_actual' => 30,
                'estado' => Lote::ESTADO_PROXIMO_A_VENCER, // dentro de la ventana de 15 días
            ]);

            // ---------- Kardex: entradas de compra ----------
            foreach ([$loteA, $loteB] as $lote) {
                MovimientoInventario::create([
                    'tipo_movimiento' => MovimientoInventario::ENTRADA_COMPRA,
                    'lote_id' => $lote->id,
                    'cantidad' => $lote->cantidad_inicial,
                    'motivo_observacion' => 'Carga inicial (seeder)',
                    'user_id' => $admin->id,
                ]);
            }

            $leche->update(['stock_actual' => 70]);
        });
    }
}
