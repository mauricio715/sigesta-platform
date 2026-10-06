<?php

use App\Http\Controllers\Api\AlertaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\DespachoController;
use App\Http\Controllers\Api\IngresoInsumoController;
use App\Http\Controllers\Api\InsumoController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\LoteController;
use App\Http\Controllers\Api\MermaController;
use App\Http\Controllers\Api\MovimientoInventarioController;
use App\Http\Controllers\Api\OrdenProduccionController;
use App\Http\Controllers\Api\ProduccionController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\RecetaController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\TrazabilidadController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SI-GESTA · API v1   (prefijo /api agregado por Laravel)
|--------------------------------------------------------------------------
| Lectura y operación diaria: admin + operador.
| Parametrización (catálogo, recetas, proveedores, usuarios): solo admin.
| Tokens con habilidad 'solo-lectura' (servicio de IA): únicamente GET.
*/

Route::prefix('v1')->group(function () {

    // ---------- Pública (con límite de intentos contra fuerza bruta) ----------
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.auth.login');

    // ---------- Autenticadas: admin y operador ----------
    Route::middleware(['auth:sanctum', 'role:admin,operador', 'token.lectura'])->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');

        // Operación diaria (antes de las rutas {insumo} para mayor claridad)
        Route::post('insumos/ingreso', [IngresoInsumoController::class, 'store'])->name('insumos.ingreso');
        Route::post('mermas', [MermaController::class, 'store'])->name('mermas.store');
        Route::post('produccion', [ProduccionController::class, 'store'])->name('produccion.store');
        Route::post('despachos', [DespachoController::class, 'store'])->name('despachos.store');

        // Catálogo (lectura)
        Route::apiResource('insumos', InsumoController::class)->only(['index', 'show']);
        Route::get('insumos/{insumo}/lotes', [InsumoController::class, 'lotes'])->name('insumos.lotes');

        Route::apiResource('productos', ProductoController::class)->only(['index', 'show']);
        Route::get('productos/{producto}/recetas', [ProductoController::class, 'recetas'])->name('productos.recetas');

        Route::apiResource('recetas', RecetaController::class)->only(['index', 'show']);
        Route::apiResource('clientes', ClienteController::class)
            ->parameters(['clientes' => 'cliente'])
            ->only(['index', 'show']);
        Route::apiResource('proveedores', ProveedorController::class)
            ->parameters(['proveedores' => 'proveedor'])
            ->only(['index', 'show']);

        // Historiales
        Route::get('despachos', [DespachoController::class, 'index'])->name('despachos.index');
        Route::get('despachos/{despacho}', [DespachoController::class, 'show'])->whereNumber('despacho')->name('despachos.show');
        Route::get('ordenes-produccion', [OrdenProduccionController::class, 'index'])->name('ordenes.index');
        Route::get('ordenes-produccion/{orden}', [OrdenProduccionController::class, 'show'])->whereNumber('orden')->name('ordenes.show');

        // Inventario, lotes y Kardex (consultas)
        Route::get('inventario/resumen', [InventarioController::class, 'resumen'])->name('inventario.resumen');
        Route::get('lotes', [LoteController::class, 'index'])->name('lotes.index');
        Route::get('movimientos', [MovimientoInventarioController::class, 'index'])->name('movimientos.index');

        // Trazabilidad
        Route::get('trazabilidad/insumo/{loteId}', [TrazabilidadController::class, 'insumo'])
            ->whereNumber('loteId')
            ->name('trazabilidad.insumo');
        Route::get('trazabilidad/producto/{loteId}', [TrazabilidadController::class, 'producto'])
            ->whereNumber('loteId')
            ->name('trazabilidad.producto');

        // Reportes descargables (PDF / Excel) y verificación de autenticidad
        Route::prefix('reportes')->name('reportes.')->group(function () {
            Route::get('trazabilidad/{lote}', [ReporteController::class, 'trazabilidad'])->whereNumber('lote')->name('trazabilidad');
            Route::get('inventario', [ReporteController::class, 'inventario'])->name('inventario');
            Route::get('kardex', [ReporteController::class, 'kardex'])->name('kardex');
            Route::get('despachos', [ReporteController::class, 'despachos'])->name('despachos');
            Route::get('mermas', [ReporteController::class, 'mermas'])->name('mermas');
            Route::get('verificar/{codigo}', [ReporteController::class, 'verificar'])->name('verificar');
        });

        // Alertas
        Route::get('alertas', [AlertaController::class, 'index'])->name('alertas.index');
        Route::patch('alertas/{alerta}/leer', [AlertaController::class, 'marcarLeida'])->name('alertas.leer');

        // ---------- Solo administrador ----------
        Route::middleware('role:admin')->group(function () {
            Route::apiResource('insumos', InsumoController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('productos', ProductoController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('clientes', ClienteController::class)
                ->parameters(['clientes' => 'cliente'])
                ->only(['store', 'update']);
            Route::apiResource('proveedores', ProveedorController::class)
                ->parameters(['proveedores' => 'proveedor'])
                ->only(['store', 'update', 'destroy']);

            // Anulaciones por error de registro (con movimiento compensatorio)
            Route::post('despachos/{despacho}/anular', [DespachoController::class, 'anular'])->whereNumber('despacho')->name('despachos.anular');
            Route::post('lotes/{lote}/anular', [LoteController::class, 'anular'])->whereNumber('lote')->name('lotes.anular');

            Route::post('recetas', [RecetaController::class, 'store'])->name('recetas.store');
            Route::patch('recetas/{receta}/estado', [RecetaController::class, 'cambiarEstado'])->name('recetas.estado');

            Route::apiResource('usuarios', UserController::class)
                ->parameters(['usuarios' => 'usuario'])
                ->only(['index', 'store', 'show', 'update']);
        });
    });
});
