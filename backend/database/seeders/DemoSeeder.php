<?php

namespace Database\Seeders;

use App\Exceptions\StockInsuficienteException;
use App\Models\Cliente;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\AlertaService;
use App\Services\AnulacionService;
use App\Services\DespachoService;
use App\Services\IngresoInsumoService;
use App\Services\MermaService;
use App\Services\ProduccionService;
use App\Services\RecetaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Datos de demostración: 27 días de operación de una panificadora simulados
 * día por día a través de los MISMOS servicios que usa la API (compras,
 * producción por BOM y FEFO, despachos, alertas nocturnas, bajas). Así el
 * Kardex, los lotes y la trazabilidad son coherentes entre sí.
 *
 * Uso (base de datos limpia):
 *   php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * Caso para la defensa: el lote de leche LEC-LV-2609 (lote del proveedor
 * LV-2609) se usó en varias producciones y llegó a varios clientes.
 */
class DemoSeeder extends Seeder
{
    private const DIAS = 27;

    private array $usuarios = [];
    private array $insumos = [];
    private array $productos = [];
    private array $clientes = [];
    private array $proveedores = [];
    private array $omitidas = [];

    public function run(): void
    {
        if (Insumo::query()->exists()) {
            $this->command?->error('La base ya tiene datos. Ejecute: php artisan migrate:fresh --seed --seeder=DemoSeeder');

            return;
        }

        $this->crearUsuarios();
        $this->crearCatalogo();
        $this->crearRecetas();

        $inicio = now()->startOfDay()->subDays(self::DIAS);

        try {
            for ($dia = 0; $dia < self::DIAS; $dia++) {
                $this->simularDia($dia, $inicio->copy()->addDays($dia));
            }
        } finally {
            Carbon::setTestNow(); // volver al reloj real pase lo que pase
        }

        // Evaluación de alertas al momento real de la carga
        app(AlertaService::class)->evaluarVencimientos();

        $this->command?->info('Demostración cargada: ' . self::DIAS . ' días de operación simulados.');
        $this->command?->line('Usuarios (contraseña "password"): admin@sigesta.com, operador@sigesta.com, juan.mamani@sigesta.com');
        $this->command?->line('Caso de trazabilidad: busque el lote LEC-LV-2609 en el módulo Trazabilidad.');

        if ($this->omitidas !== []) {
            $this->command?->warn(count($this->omitidas) . ' operaciones se omitieron por falta de stock (normal en la simulación).');
        }
    }

    // =====================================================================
    //  Catálogo
    // =====================================================================

    private function crearUsuarios(): void
    {
        $datos = [
            'admin' => ['Ana Rojas', 'admin@sigesta.com', User::ROLE_ADMIN],
            'maria' => ['María Quispe', 'operador@sigesta.com', User::ROLE_OPERADOR],
            'juan' => ['Juan Mamani', 'juan.mamani@sigesta.com', User::ROLE_OPERADOR],
        ];

        foreach ($datos as $clave => [$nombre, $email, $rol]) {
            $this->usuarios[$clave] = User::firstOrCreate(
                ['email' => $email],
                ['name' => $nombre, 'password' => 'password', 'role' => $rol, 'status' => User::STATUS_ACTIVE],
            );
        }
    }

    private function crearCatalogo(): void
    {
        foreach ([
            'molino' => ['PRV-MOL', 'Molinos Andinos S.R.L.', '1020304025', '4-4251122', 'ventas@molinosandinos.bo', 'Av. Blanco Galindo km 4, Cochabamba'],
            'lacteos' => ['PRV-LAC', 'Lácteos del Valle S.A.', '2030405016', '4-4363344', 'pedidos@lacteosdelvalle.bo', 'Quillacollo, Cochabamba'],
            'azucar' => ['PRV-AZU', 'Azucarera del Oriente Ltda.', '3040506017', '3-3427788', 'comercial@azucareraoriente.bo', 'Montero, Santa Cruz'],
            'granja' => ['PRV-GRA', 'Granja Avícola Tiquipaya', '4050607018', '72211334', 'granja.tiquipaya@gmail.com', 'Tiquipaya, Cochabamba'],
        ] as $clave => [$codigo, $razon, $nit, $telefono, $email, $direccion]) {
            $this->proveedores[$clave] = Proveedor::create([
                'codigo_proveedor' => $codigo, 'razon_social' => $razon, 'nit' => $nit,
                'telefono' => $telefono, 'email' => $email, 'direccion' => $direccion,
            ]);
        }

        foreach ([
            'harina' => ['INS-HAR', 'Harina de trigo', 'kg', 50, 30, 'Harina panadera 000'],
            'leche' => ['INS-LEC', 'Leche entera', 'lt', 30, 40, 'Leche entera pasteurizada, cadena de frío 2–6 °C'],
            'azucar' => ['INS-AZU', 'Azúcar blanca', 'kg', 15, 30, 'Azúcar refinada'],
            'mantequilla' => ['INS-MAN', 'Mantequilla', 'kg', 8, 30, 'Mantequilla sin sal, refrigerada'],
            'levadura' => ['INS-LEV', 'Levadura seca', 'kg', 1, 30, 'Levadura instantánea'],
            'huevo' => ['INS-HUE', 'Huevo de gallina', 'unidad', 120, 30, 'Huevo fresco categoría A'],
        ] as $clave => [$codigo, $nombre, $unidad, $minimo, $porcentaje, $descripcion]) {
            $this->insumos[$clave] = Insumo::create([
                'codigo' => $codigo, 'nombre' => $nombre, 'unidad_medida' => $unidad, 'stock_minimo' => $minimo,
                'porcentaje_alerta_preventiva' => $porcentaje, 'descripcion' => $descripcion,
            ]);
        }

        foreach ([
            'pan' => ['PRD-PAN', 'Pan de leche', 5, 150, 'Pan de leche de 60 g'],
            'bizcochuelo' => ['PRD-BIZ', 'Bizcochuelo', 7, 10, 'Bizcochuelo de 1 kg'],
            'galletas' => ['PRD-GAL', 'Galletas de mantequilla', 30, 60, 'Paquete de 200 g'],
        ] as $clave => [$codigo, $nombre, $vida, $minimo, $descripcion]) {
            $this->productos[$clave] = Producto::create([
                'codigo' => $codigo, 'nombre' => $nombre, 'unidad_medida' => 'unidad', 'dias_vida_util' => $vida,
                'stock_minimo' => $minimo, 'porcentaje_alerta_preventiva' => 30, 'descripcion' => $descripcion,
            ]);
        }

        foreach ([
            'prado' => ['CLI-001', 'Supermercado El Prado', '1023456789', '4-4502020', 'compras@elprado.bo', 'Av. Ballivián 555, Cochabamba'],
            'rosa' => ['CLI-002', 'Tienda Doña Rosa', '5544332', '70712345', null, 'Calle Jordán 210, Cochabamba'],
            'cafeteria' => ['CLI-003', 'Cafetería Universitaria', '6070809011', '4-4298877', 'cafeteria@universidad.edu.bo', 'Campus central, Cochabamba'],
            'norte' => ['CLI-004', 'Hipermercado Norte', '7080901022', '4-4114455', 'proveedores@hipernorte.bo', 'Av. América 1200, Cochabamba'],
        ] as $clave => [$codigo, $razon, $nit, $telefono, $email, $direccion]) {
            $this->clientes[$clave] = Cliente::create([
                'codigo_cliente' => $codigo, 'razon_social' => $razon, 'nit_ci' => $nit,
                'telefono' => $telefono, 'email' => $email, 'direccion' => $direccion,
            ]);
        }
    }

    private function crearRecetas(): void
    {
        $recetas = app(RecetaService::class);
        $i = fn (string $clave) => $this->insumos[$clave]->id;

        // Pan: versión original (queda inactiva) y versión vigente con menos azúcar
        $recetas->crearVersion([
            'producto_id' => $this->productos['pan']->id,
            'nombre_receta' => 'Pan de leche estándar',
            'rendimiento_base' => 100,
            'insumos' => [
                ['insumo_id' => $i('harina'), 'cantidad_requerida' => 5],
                ['insumo_id' => $i('leche'), 'cantidad_requerida' => 2],
                ['insumo_id' => $i('azucar'), 'cantidad_requerida' => 0.8],
                ['insumo_id' => $i('mantequilla'), 'cantidad_requerida' => 0.5],
                ['insumo_id' => $i('levadura'), 'cantidad_requerida' => 0.1],
            ],
        ]);
        $recetas->crearVersion([
            'producto_id' => $this->productos['pan']->id,
            'nombre_receta' => 'Pan de leche v2 (menos azúcar)',
            'rendimiento_base' => 100,
            'observaciones' => 'Reducción de azúcar a pedido de la cafetería universitaria.',
            'insumos' => [
                ['insumo_id' => $i('harina'), 'cantidad_requerida' => 5],
                ['insumo_id' => $i('leche'), 'cantidad_requerida' => 2],
                ['insumo_id' => $i('azucar'), 'cantidad_requerida' => 0.6],
                ['insumo_id' => $i('mantequilla'), 'cantidad_requerida' => 0.5],
                ['insumo_id' => $i('levadura'), 'cantidad_requerida' => 0.1],
            ],
        ]);

        $recetas->crearVersion([
            'producto_id' => $this->productos['bizcochuelo']->id,
            'nombre_receta' => 'Bizcochuelo clásico',
            'rendimiento_base' => 10,
            'insumos' => [
                ['insumo_id' => $i('harina'), 'cantidad_requerida' => 2.5],
                ['insumo_id' => $i('azucar'), 'cantidad_requerida' => 2],
                ['insumo_id' => $i('huevo'), 'cantidad_requerida' => 40],
                ['insumo_id' => $i('mantequilla'), 'cantidad_requerida' => 0.5],
                ['insumo_id' => $i('leche'), 'cantidad_requerida' => 1],
            ],
        ]);

        $recetas->crearVersion([
            'producto_id' => $this->productos['galletas']->id,
            'nombre_receta' => 'Galletas de mantequilla',
            'rendimiento_base' => 100,
            'insumos' => [
                ['insumo_id' => $i('harina'), 'cantidad_requerida' => 6],
                ['insumo_id' => $i('mantequilla'), 'cantidad_requerida' => 2.5],
                ['insumo_id' => $i('azucar'), 'cantidad_requerida' => 2.5],
                ['insumo_id' => $i('huevo'), 'cantidad_requerida' => 20],
            ],
        ]);
    }

    // =====================================================================
    //  Simulación diaria
    // =====================================================================

    private function simularDia(int $dia, Carbon $fecha): void
    {
        $maria = $this->usuarios['maria']->id;
        $juan = $this->usuarios['juan']->id;
        $turno = $dia % 2 === 0 ? $maria : $juan;

        // 00:05 — motor de alertas nocturno
        $this->a($fecha, 0, 5);
        app(AlertaService::class)->evaluarVencimientos();

        // 07:30 — recepción de compras
        $this->a($fecha, 7, 30);
        foreach ($this->comprasDelDia($dia) as [$insumo, $proveedor, $cantidad, $diasVida, $diasDesdeFabricacion, $extra]) {
            $this->intentar(fn () => app(IngresoInsumoService::class)->registrarEntradaInsumo([
                'insumo_id' => $this->insumos[$insumo]->id,
                'proveedor_id' => $this->proveedores[$proveedor]->id,
                'cantidad' => $cantidad,
                'fecha_fabricacion' => $fecha->copy()->subDays($diasDesdeFabricacion)->toDateString(),
                'fecha_vencimiento' => $fecha->copy()->subDays($diasDesdeFabricacion)->addDays($diasVida)->toDateString(),
                'documento_referencia' => sprintf('FAC-%05d', 1000 + $dia * 10 + strlen($insumo)),
            ] + $extra, $maria));
        }

        // 08:30 — bajas de lotes vencidos (los últimos días quedan pendientes, para ver alertas)
        if ($dia < self::DIAS - 2) {
            $this->a($fecha, 8, 30);
            Lote::query()->where('estado', Lote::ESTADO_VENCIDO)->where('cantidad_actual', '>', 0)->get()
                ->each(fn (Lote $lote) => $this->intentar(fn () => app(MermaService::class)->registrarMerma(
                    $lote->id,
                    (float) $lote->cantidad_actual,
                    'VENCIMIENTO',
                    $turno,
                    'Retirado del almacén y desechado según procedimiento de no conformes.',
                )));
        }

        // 10:00 — producción
        $this->a($fecha, 10, 0);
        $produccion = app(ProduccionService::class);
        if ($dia % 7 !== 6) {
            $this->intentar(fn () => $produccion->ejecutarOrdenProduccion($this->productos['pan']->id, 300, $turno));
        }
        if ($dia % 3 === 0) {
            $this->intentar(fn () => $produccion->ejecutarOrdenProduccion($this->productos['bizcochuelo']->id, 20, $turno));
        }
        if (in_array($dia, [2, 9, 16, 23], true)) {
            $this->intentar(fn () => $produccion->ejecutarOrdenProduccion($this->productos['galletas']->id, 200, $turno));
        }

        // 15:00 — despachos
        $this->a($fecha, 15, 0);
        $despachos = app(DespachoService::class);
        $clientesPan = ['prado', 'rosa', 'cafeteria', 'norte'];
        if ($dia % 7 !== 6) {
            $this->intentar(fn () => $despachos->registrarDespacho(
                $this->clientes[$clientesPan[$dia % 4]]->id,
                [['producto_id' => $this->productos['pan']->id, 'cantidad' => 260, 'precio_unitario' => 0.8]],
                $turno,
            ));
        }
        if ($dia % 3 === 1) {
            $this->intentar(fn () => $despachos->registrarDespacho(
                $this->clientes['cafeteria']->id,
                [['producto_id' => $this->productos['bizcochuelo']->id, 'cantidad' => 15, 'precio_unitario' => 35]],
                $turno,
            ));
        }
        if (in_array($dia, [4, 11, 18, 25], true)) {
            $this->intentar(fn () => $despachos->registrarDespacho(
                $this->clientes['norte']->id,
                [
                    ['producto_id' => $this->productos['galletas']->id, 'cantidad' => 120, 'precio_unitario' => 6.5],
                    ['producto_id' => $this->productos['pan']->id, 'cantidad' => 30, 'precio_unitario' => 0.8],
                ],
                $turno,
                'Entrega en muelle de recepción, turno tarde.',
            ));
        }

        // 16:00 — incidencias puntuales
        $this->a($fecha, 16, 0);
        $this->incidencias($dia, $turno);
    }

    /** [insumo, proveedor, cantidad, días de vida, días desde fabricación, datos extra] */
    private function comprasDelDia(int $dia): array
    {
        $compras = [];

        if (in_array($dia, [0, 12, 21], true)) {
            $compras[] = ['harina', 'molino', 220, 180, 10, ['codigo_lote_proveedor' => "MA-{$dia}A"]];
        }
        if ($dia % 4 === 0) {
            $extra = ['codigo_lote_proveedor' => sprintf('LV-%02d%02d', $dia, 9)];
            if ($dia === 12) {
                // Lote del caso de trazabilidad
                $extra = ['codigo_lote' => 'LEC-LV-2609', 'codigo_lote_proveedor' => 'LV-2609'];
            }
            $compras[] = ['leche', 'lacteos', 45, 10, 1, $extra];
        }
        if (in_array($dia, [0, 9, 18], true)) {
            $compras[] = ['azucar', 'azucar', 50, 365, 30, []];
            $compras[] = ['mantequilla', 'lacteos', 25, 60, 3, []];
        }
        if (in_array($dia, [0, 12], true)) {
            $compras[] = ['levadura', 'molino', 4, 90, 15, []];
        }
        if ($dia % 7 === 0) {
            $compras[] = ['huevo', 'granja', 420, 21, 1, []];
        }

        return $compras;
    }

    private function incidencias(int $dia, int $responsable): void
    {
        $mermas = app(MermaService::class);

        if ($dia === 10) {
            $lote = Lote::where('producto_id', $this->productos['galletas']->id)->where('cantidad_actual', '>', 10)->orderBy('id')->first();
            $lote && $this->intentar(fn () => $mermas->registrarMerma($lote->id, 10, 'DANO_EMPAQUE', $responsable, 'Paquetes aplastados durante el apilado.'));
        }

        if ($dia === 15) {
            $lote = Lote::where('insumo_id', $this->insumos['leche']->id)->where('cantidad_actual', '>', 3)
                ->whereIn('estado', Lote::ESTADOS_DISPONIBLES)->orderBy('fecha_vencimiento')->first();
            $lote && $this->intentar(fn () => $mermas->registrarMerma($lote->id, 3, 'DETERIORO', $responsable, 'Bolsas infladas: se rompió la cadena de frío en recepción.'));
        }

        // Despacho registrado al cliente equivocado y anulado por el administrador
        if ($dia === 20) {
            $despacho = $this->intentar(fn () => app(DespachoService::class)->registrarDespacho(
                $this->clientes['prado']->id,
                [['producto_id' => $this->productos['pan']->id, 'cantidad' => 20, 'precio_unitario' => 0.8]],
                $responsable,
            ));

            if ($despacho) {
                $this->a(Carbon::now(), 16, 20);
                $this->intentar(fn () => app(AnulacionService::class)->anularDespacho(
                    $despacho->id,
                    $this->usuarios['admin']->id,
                    'Registrado al cliente equivocado; el producto no salió de planta.',
                ));
            }
        }
    }

    // ---------- Utilidades ----------

    /** Fija el reloj simulado */
    private function a(Carbon $fecha, int $hora, int $minuto): void
    {
        Carbon::setTestNow($fecha->copy()->setTime($hora, $minuto));
    }

    /** Ejecuta una operación; si el stock no alcanza en la simulación, la omite */
    private function intentar(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (StockInsuficienteException|InvalidArgumentException $e) {
            $this->omitidas[] = $e->getMessage();

            return null;
        } catch (Throwable $e) {
            Carbon::setTestNow();
            throw $e;
        }
    }
}
