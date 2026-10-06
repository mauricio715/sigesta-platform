<?php

namespace App\Services;

use App\Models\Despacho;
use App\Models\DespachoDetalle;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Reportes\Formato;
use App\Reportes\Reporte;
use App\Reportes\Seccion;
use App\Support\Cantidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Arma el contenido de cada reporte a partir de los mismos datos que usa la
 * API. No conoce el formato de salida: devuelve un Reporte que luego se
 * convierte en PDF o Excel.
 */
class ReporteService
{
    public const TIPOS_MOVIMIENTO = [
        MovimientoInventario::ENTRADA_COMPRA => 'Compra',
        MovimientoInventario::CONSUMO_PRODUCCION => 'Consumo en producción',
        MovimientoInventario::INGRESO_PRODUCCION => 'Ingreso de producción',
        MovimientoInventario::SALIDA_VENTA => 'Despacho a cliente',
        MovimientoInventario::MERMA_DESECHO => 'Merma',
        MovimientoInventario::BAJA_VENCIMIENTO => 'Baja por vencimiento',
        MovimientoInventario::DEVOLUCION_CLIENTE => 'Anulación de despacho',
        MovimientoInventario::ANULACION_COMPRA => 'Anulación de compra',
    ];

    public const MOTIVOS_MERMA = [
        'VENCIMIENTO' => 'Vencimiento',
        'DETERIORO' => 'Deterioro',
        'CONTAMINACION' => 'Contaminación',
        'DANO_EMPAQUE' => 'Daño de empaque',
        'ERROR_PROCESO' => 'Error de proceso',
        'CONTROL_CALIDAD' => 'Rechazo de control de calidad',
        'OTRO' => 'Otro',
    ];

    private const UNIDADES = ['kg' => 'kg', 'gr' => 'g', 'lt' => 'L', 'ml' => 'mL', 'unidad' => 'u.'];

    private const ESTADOS_LOTE = [
        'ACTIVO' => 'Vigente', 'PROXIMO_A_VENCER' => 'Próximo a vencer', 'VENCIDO' => 'Vencido',
        'AGOTADO' => 'Agotado', 'ANULADO' => 'Anulado',
    ];

    public function __construct(
        private readonly TrazabilidadService $trazabilidad,
    ) {}

    // =====================================================================
    //  Trazabilidad (informe para retiro de producto / auditoría)
    // =====================================================================

    public function trazabilidad(Lote $lote): Reporte
    {
        return $lote->tipo_item === Lote::TIPO_INSUMO
            ? $this->trazabilidadInsumo($lote)
            : $this->trazabilidadProducto($lote);
    }

    private function trazabilidadInsumo(Lote $lote): Reporte
    {
        $datos = $this->trazabilidad->rastrearInsumoHaciaAdelante($lote->id);
        $insumo = $datos['lote_insumo']['insumo'];
        $unidad = self::UNIDADES[$insumo['unidad_medida']] ?? $insumo['unidad_medida'];
        $proveedor = $datos['lote_insumo']['proveedor'];

        $entregas = [];
        foreach ($datos['clientes_afectados'] as $cliente) {
            foreach ($cliente['entregas'] as $entrega) {
                $entregas[] = [
                    'cliente' => $cliente['razon_social'],
                    'nit' => $cliente['nit_ci'],
                    'contacto' => trim(implode(' · ', array_filter([$cliente['telefono'], $cliente['email']]))),
                    'despacho' => $entrega['codigo_despacho'],
                    'fecha' => $entrega['fecha_despacho'],
                    'producto' => $entrega['producto'],
                    'lote' => $entrega['lote_producto'],
                    'cantidad' => $entrega['cantidad'],
                ];
            }
        }

        $lotesProducto = [];
        $saldoEnPlanta = [];
        foreach ($datos['producciones'] as $p) {
            $lp = $p['lote_producto'];
            $lotesProducto[] = [
                'orden' => $p['orden_produccion']['codigo_orden'],
                'fecha' => $p['orden_produccion']['fecha_produccion'],
                'consumido' => $p['cantidad_insumo_consumida'],
                'producto' => $p['producto']['nombre'],
                'lote' => $lp['codigo_lote'] ?? null,
                'vencimiento' => $lp['fecha_vencimiento'] ?? null,
                'producido' => $lp['cantidad_inicial'] ?? null,
                'saldo' => $lp['cantidad_actual'] ?? null,
                'estado' => self::ESTADOS_LOTE[$lp['estado'] ?? ''] ?? null,
            ];
            if ($lp && Cantidad::aMilesimas($lp['cantidad_actual']) > 0) {
                $saldoEnPlanta[] = "{$lp['codigo_lote']} ({$p['producto']['nombre']}): " . Formato::cantidad($lp['cantidad_actual']) . ' u.';
            }
        }

        $notas = [];
        if (Cantidad::aMilesimas($datos['lote_insumo']['cantidad_actual']) > 0) {
            $notas[] = "El lote de insumo conserva un saldo de " . Formato::cantidad($datos['lote_insumo']['cantidad_actual']) . " {$unidad} en planta.";
        }
        if ($saldoEnPlanta !== []) {
            $notas[] = 'Lotes de producto elaborados con este insumo que aún tienen saldo en planta: ' . implode('; ', $saldoEnPlanta) . '.';
        }
        $notas[] = "Producto entregado a {$datos['resumen']['clientes_afectados']} cliente(s) en {$datos['resumen']['despachos']} despacho(s). Los despachos anulados no se incluyen.";

        return new Reporte(
            tipo: 'trazabilidad',
            titulo: 'Informe de trazabilidad hacia adelante',
            subtitulo: "Lote de insumo {$lote->codigo_lote} · {$insumo['nombre']}",
            resumen: [
                ['Lote interno', $lote->codigo_lote],
                ['Lote del proveedor', $lote->codigo_lote_proveedor ?? '—'],
                ['Insumo', "{$insumo['nombre']} ({$insumo['codigo']})"],
                ['Proveedor', $proveedor ? trim("{$proveedor['razon_social']} " . ($proveedor['nit'] ? "· NIT {$proveedor['nit']}" : '')) : 'Sin proveedor registrado'],
                ['Fabricación', Formato::celda('fecha', $datos['lote_insumo']['fecha_fabricacion'])],
                ['Vencimiento', Formato::celda('fecha', $datos['lote_insumo']['fecha_vencimiento'])],
                ['Cantidad recibida', Formato::cantidad($datos['lote_insumo']['cantidad_inicial']) . " {$unidad}"],
                ['Saldo actual', Formato::cantidad($datos['lote_insumo']['cantidad_actual']) . " {$unidad} (" . (self::ESTADOS_LOTE[$datos['lote_insumo']['estado']] ?? '') . ')'],
                ['Órdenes de producción', (string) $datos['resumen']['ordenes_produccion']],
                ['Clientes alcanzados', (string) $datos['resumen']['clientes_afectados']],
            ],
            secciones: [
                new Seccion(
                    titulo: 'Clientes que recibieron producto elaborado con este lote',
                    descripcion: 'Contactos para una eventual notificación o retiro de producto.',
                    columnas: [
                        ['clave' => 'cliente', 'titulo' => 'Cliente', 'ancho' => 26],
                        ['clave' => 'nit', 'titulo' => 'NIT/CI', 'ancho' => 13],
                        ['clave' => 'contacto', 'titulo' => 'Contacto', 'ancho' => 30],
                        ['clave' => 'despacho', 'titulo' => 'Despacho', 'ancho' => 20],
                        ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha_hora', 'ancho' => 16],
                        ['clave' => 'producto', 'titulo' => 'Producto', 'ancho' => 20],
                        ['clave' => 'lote', 'titulo' => 'Lote de producto', 'ancho' => 24],
                        ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'cantidad', 'ancho' => 10],
                    ],
                    filas: $entregas,
                    vacio: 'Ningún producto elaborado con este lote fue despachado.',
                ),
                new Seccion(
                    titulo: 'Producción en la que se usó el lote',
                    columnas: [
                        ['clave' => 'orden', 'titulo' => 'Orden', 'ancho' => 18],
                        ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha_hora', 'ancho' => 16],
                        ['clave' => 'consumido', 'titulo' => "Insumo usado ({$unidad})", 'tipo' => 'cantidad', 'ancho' => 12],
                        ['clave' => 'producto', 'titulo' => 'Producto', 'ancho' => 20],
                        ['clave' => 'lote', 'titulo' => 'Lote generado', 'ancho' => 24],
                        ['clave' => 'vencimiento', 'titulo' => 'Vence', 'tipo' => 'fecha', 'ancho' => 12],
                        ['clave' => 'producido', 'titulo' => 'Producido', 'tipo' => 'cantidad', 'ancho' => 11],
                        ['clave' => 'saldo', 'titulo' => 'Saldo en planta', 'tipo' => 'cantidad', 'ancho' => 12],
                        ['clave' => 'estado', 'titulo' => 'Estado', 'ancho' => 14],
                    ],
                    filas: $lotesProducto,
                    vacio: 'El lote todavía no se usó en producción.',
                ),
            ],
            notas: $notas,
            conFirmas: true,
            orientacion: 'landscape',
            parametros: ['lote_id' => $lote->id, 'direccion' => 'adelante'],
            nombreArchivo: "trazabilidad_{$lote->codigo_lote}",
        );
    }

    private function trazabilidadProducto(Lote $lote): Reporte
    {
        $datos = $this->trazabilidad->rastrearProductoHaciaAtras($lote->id);
        $producto = $datos['lote_producto']['producto'];
        $orden = $datos['orden_produccion'];
        $receta = $datos['receta'];

        $lotesProveedor = Lote::query()
            ->whereIn('id', array_map(fn ($o) => $o['lote']['id'], $datos['insumos_origen']))
            ->pluck('codigo_lote_proveedor', 'id');

        $origen = array_map(fn ($o) => [
            'insumo' => $o['insumo']['nombre'],
            'lote' => $o['lote']['codigo_lote'],
            'lote_proveedor' => $lotesProveedor[$o['lote']['id']] ?? null,
            'proveedor' => $o['proveedor']['razon_social'] ?? 'Sin proveedor registrado',
            'fabricacion' => $o['lote']['fecha_fabricacion'],
            'vencimiento' => $o['lote']['fecha_vencimiento'],
            'cantidad' => $o['cantidad_consumida'],
            'unidad' => self::UNIDADES[$o['insumo']['unidad_medida']] ?? $o['insumo']['unidad_medida'],
        ], $datos['insumos_origen']);

        $despachos = DespachoDetalle::query()
            ->where('lote_producto_id', $lote->id)
            ->with('despacho.cliente')
            ->orderBy('id')
            ->get()
            ->map(fn (DespachoDetalle $d) => [
                'despacho' => $d->despacho->codigo_despacho,
                'fecha' => $d->despacho->fecha_despacho->toDateTimeString(),
                'cliente' => $d->despacho->cliente->razon_social,
                'nit' => $d->despacho->cliente->nit_ci,
                'contacto' => trim(implode(' · ', array_filter([$d->despacho->cliente->telefono, $d->despacho->cliente->email]))),
                'cantidad' => $d->cantidad,
                'estado' => $d->despacho->estado === Despacho::ESTADO_ANULADO ? 'Anulado' : 'Entregado',
            ])
            ->all();

        return new Reporte(
            tipo: 'trazabilidad',
            titulo: 'Informe de trazabilidad hacia atrás',
            subtitulo: "Lote de producto {$lote->codigo_lote} · {$producto['nombre']}",
            resumen: [
                ['Lote', $lote->codigo_lote],
                ['Producto', "{$producto['nombre']} ({$producto['codigo']})"],
                ['Fabricación', Formato::celda('fecha', $datos['lote_producto']['fecha_fabricacion'])],
                ['Vencimiento', Formato::celda('fecha', $datos['lote_producto']['fecha_vencimiento'])],
                ['Cantidad producida', Formato::cantidad($datos['lote_producto']['cantidad_inicial']) . ' u.'],
                ['Saldo en planta', Formato::cantidad($datos['lote_producto']['cantidad_actual']) . ' u. (' . (self::ESTADOS_LOTE[$datos['lote_producto']['estado']] ?? '') . ')'],
                ['Orden de producción', $orden['codigo_orden'] ?? 'No registrada'],
                ['Responsable', $orden['responsable'] ?? '—'],
                ['Receta aplicada', $receta ? "{$receta['nombre_receta']} (rinde " . Formato::cantidad($receta['rendimiento_base']) . ')' : '—'],
            ],
            secciones: [
                new Seccion(
                    titulo: 'Insumos de origen',
                    descripcion: 'Lotes exactos consumidos según FEFO, con su proveedor.',
                    columnas: [
                        ['clave' => 'insumo', 'titulo' => 'Insumo', 'ancho' => 20],
                        ['clave' => 'lote', 'titulo' => 'Lote interno', 'ancho' => 22],
                        ['clave' => 'lote_proveedor', 'titulo' => 'Lote proveedor', 'ancho' => 15],
                        ['clave' => 'proveedor', 'titulo' => 'Proveedor', 'ancho' => 26],
                        ['clave' => 'fabricacion', 'titulo' => 'Fabricación', 'tipo' => 'fecha', 'ancho' => 12],
                        ['clave' => 'vencimiento', 'titulo' => 'Vence', 'tipo' => 'fecha', 'ancho' => 12],
                        ['clave' => 'cantidad', 'titulo' => 'Consumido', 'tipo' => 'cantidad', 'ancho' => 11],
                        ['clave' => 'unidad', 'titulo' => 'Unidad', 'ancho' => 8],
                    ],
                    filas: $origen,
                    vacio: 'Este lote no proviene de una orden de producción registrada.',
                ),
                new Seccion(
                    titulo: 'Despachos de este lote',
                    columnas: [
                        ['clave' => 'despacho', 'titulo' => 'Despacho', 'ancho' => 20],
                        ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha_hora', 'ancho' => 16],
                        ['clave' => 'cliente', 'titulo' => 'Cliente', 'ancho' => 26],
                        ['clave' => 'nit', 'titulo' => 'NIT/CI', 'ancho' => 13],
                        ['clave' => 'contacto', 'titulo' => 'Contacto', 'ancho' => 30],
                        ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'cantidad', 'ancho' => 10],
                        ['clave' => 'estado', 'titulo' => 'Estado', 'ancho' => 11],
                    ],
                    filas: $despachos,
                    vacio: 'Este lote todavía no fue despachado.',
                ),
            ],
            conFirmas: true,
            orientacion: 'landscape',
            parametros: ['lote_id' => $lote->id, 'direccion' => 'atras'],
            nombreArchivo: "trazabilidad_{$lote->codigo_lote}",
        );
    }

    // =====================================================================
    //  Estado de inventario
    // =====================================================================

    public function inventario(): Reporte
    {
        $hoy = now()->startOfDay();
        $items = Insumo::query()->orderBy('nombre')->get()->concat(Producto::query()->orderBy('nombre')->get());

        $vigentes = Lote::query()->disponibles()->fefo()->with(['insumo', 'producto', 'proveedor'])->get();
        $vencidos = Lote::query()
            ->where('cantidad_actual', '>', 0)
            ->where('estado', '!=', Lote::ESTADO_ANULADO)
            ->where(fn ($q) => $q->where('estado', Lote::ESTADO_VENCIDO)->orWhereDate('fecha_vencimiento', '<', $hoy->toDateString()))
            ->with(['insumo', 'producto'])
            ->orderBy('fecha_vencimiento')
            ->get();

        $porItem = $vigentes->groupBy(fn (Lote $l) => $l->tipo_item . ':' . ($l->insumo_id ?? $l->producto_id));

        $filasItems = $items->map(function ($item) use ($porItem) {
            $tipo = $item instanceof Insumo ? Lote::TIPO_INSUMO : Lote::TIPO_PRODUCTO;
            $lotes = $porItem->get("{$tipo}:{$item->id}", collect());
            $bajo = Cantidad::aMilesimas($item->stock_actual) < Cantidad::aMilesimas($item->stock_minimo);

            return [
                'tipo' => $tipo === Lote::TIPO_INSUMO ? 'Insumo' : 'Producto',
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'unidad' => self::UNIDADES[$item->unidad_medida] ?? $item->unidad_medida,
                'stock' => $item->stock_actual,
                'minimo' => $item->stock_minimo,
                'situacion' => $bajo ? 'Bajo el mínimo' : 'Normal',
                'lotes' => $lotes->count(),
                'proximo' => $lotes->first()?->fecha_vencimiento?->toDateString(),
            ];
        })->values()->all();

        $filaLote = fn (Lote $l) => [
            'item' => ($l->insumo ?? $l->producto)?->nombre,
            'lote' => $l->codigo_lote,
            'proveedor' => $l->proveedor?->razon_social,
            'vencimiento' => $l->fecha_vencimiento->toDateString(),
            'dias' => (int) round($hoy->diffInDays($l->fecha_vencimiento->copy()->startOfDay(), false)),
            'saldo' => $l->cantidad_actual,
            'unidad' => self::UNIDADES[($l->insumo ?? $l->producto)?->unidad_medida] ?? '',
            'estado' => self::ESTADOS_LOTE[$l->estado] ?? $l->estado,
        ];

        $bajos = count(array_filter($filasItems, fn ($f) => $f['situacion'] === 'Bajo el mínimo'));

        $columnasLote = [
            ['clave' => 'item', 'titulo' => 'Ítem', 'ancho' => 22],
            ['clave' => 'lote', 'titulo' => 'Lote', 'ancho' => 24],
            ['clave' => 'proveedor', 'titulo' => 'Proveedor', 'ancho' => 24],
            ['clave' => 'vencimiento', 'titulo' => 'Vence', 'tipo' => 'fecha', 'ancho' => 12],
            ['clave' => 'dias', 'titulo' => 'Días', 'tipo' => 'entero', 'ancho' => 7],
            ['clave' => 'saldo', 'titulo' => 'Saldo', 'tipo' => 'cantidad', 'ancho' => 11],
            ['clave' => 'unidad', 'titulo' => 'Unidad', 'ancho' => 8],
            ['clave' => 'estado', 'titulo' => 'Estado', 'ancho' => 15],
        ];

        return new Reporte(
            tipo: 'inventario',
            titulo: 'Estado de inventario',
            subtitulo: 'Stock utilizable y lotes en orden de salida (FEFO) al ' . now()->format('d/m/Y H:i'),
            resumen: [
                ['Insumos', (string) $items->filter(fn ($i) => $i instanceof Insumo)->count()],
                ['Productos terminados', (string) $items->filter(fn ($i) => $i instanceof Producto)->count()],
                ['Ítems bajo el mínimo', (string) $bajos],
                ['Lotes vigentes', (string) $vigentes->count()],
                ['Lotes próximos a vencer', (string) $vigentes->where('estado', Lote::ESTADO_PROXIMO_A_VENCER)->count()],
                ['Lotes vencidos con saldo', (string) $vencidos->count()],
            ],
            secciones: [
                new Seccion(
                    titulo: 'Stock por ítem',
                    columnas: [
                        ['clave' => 'tipo', 'titulo' => 'Tipo', 'ancho' => 10],
                        ['clave' => 'codigo', 'titulo' => 'Código', 'ancho' => 12],
                        ['clave' => 'nombre', 'titulo' => 'Nombre', 'ancho' => 26],
                        ['clave' => 'stock', 'titulo' => 'Stock utilizable', 'tipo' => 'cantidad', 'ancho' => 14],
                        ['clave' => 'minimo', 'titulo' => 'Mínimo', 'tipo' => 'cantidad', 'ancho' => 10],
                        ['clave' => 'unidad', 'titulo' => 'Unidad', 'ancho' => 8],
                        ['clave' => 'situacion', 'titulo' => 'Situación', 'ancho' => 15],
                        ['clave' => 'lotes', 'titulo' => 'Lotes vigentes', 'tipo' => 'entero', 'ancho' => 12],
                        ['clave' => 'proximo', 'titulo' => 'Próximo vencimiento', 'tipo' => 'fecha', 'ancho' => 16],
                    ],
                    filas: $filasItems,
                ),
                new Seccion(
                    titulo: 'Lotes vigentes en orden de salida (FEFO)',
                    descripcion: 'Dentro de cada ítem, el primer lote listado es el que debe consumirse o despacharse primero.',
                    columnas: $columnasLote,
                    filas: $vigentes->sortBy(fn (Lote $l) => (($l->insumo ?? $l->producto)?->nombre ?? '') . $l->fecha_vencimiento->format('Ymd') . sprintf('%08d', $l->id))
                        ->map($filaLote)->values()->all(),
                ),
                new Seccion(
                    titulo: 'Lotes vencidos con saldo (retenidos)',
                    descripcion: 'Bloqueados para producción y despacho. Deben darse de baja.',
                    columnas: $columnasLote,
                    filas: $vencidos->map($filaLote)->values()->all(),
                    vacio: 'No hay lotes vencidos con saldo.',
                ),
            ],
            orientacion: 'landscape',
            nombreArchivo: 'inventario',
        );
    }

    // =====================================================================
    //  Kardex
    // =====================================================================

    /**
     * Kardex cronológico. Con lote_id o item_id (unidad única) agrega
     * columna de saldo físico acumulado, partiendo del saldo anterior al período.
     */
    public function kardex(array $filtros, int $maxFilas): Reporte
    {
        $base = $this->consultaKardex($filtros);
        $total = (clone $base)->count();

        if ($total > $maxFilas) {
            throw new InvalidArgumentException("El reporte tendría {$total} movimientos (máximo {$maxFilas}). Acote el período o los filtros.");
        }

        $movimientos = $base->with(['lote.insumo', 'lote.producto', 'user'])->orderBy('created_at')->orderBy('id')->get();
        $conSaldo = ! empty($filtros['lote_id']) || ! empty($filtros['item_id']);

        $saldo = 0;
        if ($conSaldo && ! empty($filtros['fecha_inicio'])) {
            $previos = $this->consultaKardex(array_diff_key($filtros, ['fecha_inicio' => 1, 'fecha_fin' => 1, 'tipo_movimiento' => 1]))
                ->where('created_at', '<', Carbon::parse($filtros['fecha_inicio'])->startOfDay())
                ->get(['tipo_movimiento', 'cantidad']);
            foreach ($previos as $m) {
                $saldo += $this->signo($m) * Cantidad::aMilesimas($m->cantidad);
            }
        }
        $saldoInicial = $saldo;

        $entradas = 0;
        $salidas = 0;
        $filas = [];
        foreach ($movimientos as $m) {
            $cantidad = Cantidad::aMilesimas($m->cantidad);
            $entrada = $this->signo($m) > 0;
            if ($entrada) {
                $entradas += $cantidad;
                $saldo += $cantidad;
            } else {
                $salidas += $cantidad;
                $saldo -= $cantidad;
            }
            $item = $m->lote?->insumo ?? $m->lote?->producto;

            $filas[] = [
                'fecha' => $m->created_at->toDateTimeString(),
                'tipo' => self::TIPOS_MOVIMIENTO[$m->tipo_movimiento] ?? $m->tipo_movimiento,
                'item' => $item?->nombre,
                'lote' => $m->lote?->codigo_lote,
                'entrada' => $entrada ? Cantidad::aDecimal($cantidad) : null,
                'salida' => $entrada ? null : Cantidad::aDecimal($cantidad),
                'saldo' => $conSaldo ? Cantidad::aDecimal($saldo) : null,
                'unidad' => self::UNIDADES[$item?->unidad_medida] ?? '',
                'motivo' => $m->motivo_observacion,
                'responsable' => $m->user?->name,
            ];
        }

        $columnas = [
            ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha_hora', 'ancho' => 16],
            ['clave' => 'tipo', 'titulo' => 'Movimiento', 'ancho' => 20],
            ['clave' => 'item', 'titulo' => 'Ítem', 'ancho' => 20],
            ['clave' => 'lote', 'titulo' => 'Lote', 'ancho' => 24],
            ['clave' => 'entrada', 'titulo' => 'Entrada', 'tipo' => 'cantidad', 'ancho' => 10],
            ['clave' => 'salida', 'titulo' => 'Salida', 'tipo' => 'cantidad', 'ancho' => 10],
        ];
        if ($conSaldo) {
            $columnas[] = ['clave' => 'saldo', 'titulo' => 'Saldo físico', 'tipo' => 'cantidad', 'ancho' => 11];
        }
        $columnas[] = ['clave' => 'unidad', 'titulo' => 'Unidad', 'ancho' => 7];
        $columnas[] = ['clave' => 'motivo', 'titulo' => 'Motivo', 'ancho' => 40];
        $columnas[] = ['clave' => 'responsable', 'titulo' => 'Responsable', 'ancho' => 18];

        $resumen = [['Período', $this->textoPeriodo($filtros)], ['Movimientos', (string) count($filas)]];
        if (! empty($filtros['tipo_movimiento'])) {
            $resumen[] = ['Tipo de movimiento', self::TIPOS_MOVIMIENTO[$filtros['tipo_movimiento']] ?? $filtros['tipo_movimiento']];
        }
        if ($conSaldo) {
            $resumen[] = ['Alcance', $this->textoAlcance($filtros)];
            $resumen[] = ['Saldo al inicio', Formato::cantidad(Cantidad::aDecimal($saldoInicial))];
            $resumen[] = ['Saldo al cierre', Formato::cantidad(Cantidad::aDecimal($saldo))];
        }

        return new Reporte(
            tipo: 'kardex',
            titulo: 'Kardex de inventario',
            subtitulo: $conSaldo ? $this->textoAlcance($filtros) : 'Todos los ítems',
            resumen: $resumen,
            secciones: [new Seccion(
                titulo: 'Movimientos',
                columnas: $columnas,
                filas: $filas,
                totales: $conSaldo ? ['tipo' => 'Totales del período', 'entrada' => Cantidad::aDecimal($entradas), 'salida' => Cantidad::aDecimal($salidas)] : null,
            )],
            notas: $conSaldo ? ['El saldo físico incluye lotes vencidos que aún no se dieron de baja; el stock utilizable puede ser menor.'] : [],
            orientacion: 'landscape',
            parametros: $filtros,
            nombreArchivo: 'kardex',
        );
    }

    // =====================================================================
    //  Despachos
    // =====================================================================

    public function despachos(array $filtros, int $maxFilas): Reporte
    {
        $consulta = DespachoDetalle::query()
            ->whereHas('despacho', function (Builder $q) use ($filtros) {
                $q->when($filtros['cliente_id'] ?? null, fn ($w, $id) => $w->where('cliente_id', $id))
                    ->when($filtros['estado'] ?? null, fn ($w, $e) => $w->where('estado', $e))
                    ->when($filtros['fecha_inicio'] ?? null, fn ($w, $f) => $w->where('fecha_despacho', '>=', Carbon::parse($f)->startOfDay()))
                    ->when($filtros['fecha_fin'] ?? null, fn ($w, $f) => $w->where('fecha_despacho', '<=', Carbon::parse($f)->endOfDay()));
            });

        $total = (clone $consulta)->count();
        if ($total > $maxFilas) {
            throw new InvalidArgumentException("El reporte tendría {$total} filas (máximo {$maxFilas}). Acote el período o los filtros.");
        }

        $detalles = $consulta->with(['despacho.cliente', 'despacho.user', 'producto', 'loteProducto'])->get()
            ->sortBy(fn ($d) => $d->despacho->fecha_despacho->format('YmdHis') . sprintf('%08d', $d->id))->values();

        $filas = [];
        $porCliente = [];
        $totalCompletado = 0;
        foreach ($detalles as $d) {
            $anulado = $d->despacho->estado === Despacho::ESTADO_ANULADO;
            $subtotal = $d->precio_unitario === null ? null
                : (int) round(Cantidad::aMilesimas($d->precio_unitario) * Cantidad::aMilesimas($d->cantidad) / Cantidad::ESCALA);

            $filas[] = [
                'fecha' => $d->despacho->fecha_despacho->toDateTimeString(),
                'despacho' => $d->despacho->codigo_despacho,
                'estado' => $anulado ? 'Anulado' : 'Entregado',
                'cliente' => $d->despacho->cliente->razon_social,
                'producto' => $d->producto->nombre,
                'lote' => $d->loteProducto->codigo_lote,
                'cantidad' => $d->cantidad,
                'precio' => $d->precio_unitario,
                'subtotal' => $subtotal === null ? null : Cantidad::aDecimal($subtotal),
                'responsable' => $d->despacho->user->name,
            ];

            if (! $anulado) {
                $clave = $d->despacho->cliente->razon_social;
                $porCliente[$clave] ??= ['cliente' => $clave, 'despachos' => [], 'importe' => 0];
                $porCliente[$clave]['despachos'][$d->despacho_id] = true;
                $porCliente[$clave]['importe'] += $subtotal ?? 0;
                $totalCompletado += $subtotal ?? 0;
            }
        }

        $filasClientes = array_map(fn ($c) => [
            'cliente' => $c['cliente'],
            'despachos' => count($c['despachos']),
            'importe' => Cantidad::aDecimal($c['importe']),
        ], array_values($porCliente));
        usort($filasClientes, fn ($a, $b) => (float) $b['importe'] <=> (float) $a['importe']);

        $anulados = $detalles->filter(fn ($d) => $d->despacho->estado === Despacho::ESTADO_ANULADO)->pluck('despacho_id')->unique()->count();

        return new Reporte(
            tipo: 'despachos',
            titulo: 'Reporte de despachos a clientes',
            subtitulo: $this->textoPeriodo($filtros),
            resumen: [
                ['Período', $this->textoPeriodo($filtros)],
                ['Despachos entregados', (string) collect($porCliente)->sum(fn ($c) => count($c['despachos']))],
                ['Despachos anulados', (string) $anulados],
                ['Importe entregado', 'Bs ' . Formato::celda('moneda', Cantidad::aDecimal($totalCompletado))],
            ],
            secciones: [
                new Seccion(
                    titulo: 'Resumen por cliente',
                    columnas: [
                        ['clave' => 'cliente', 'titulo' => 'Cliente', 'ancho' => 32],
                        ['clave' => 'despachos', 'titulo' => 'Despachos', 'tipo' => 'entero', 'ancho' => 12],
                        ['clave' => 'importe', 'titulo' => 'Importe (Bs)', 'tipo' => 'moneda', 'ancho' => 14],
                    ],
                    filas: $filasClientes,
                    totales: ['cliente' => 'Total', 'importe' => Cantidad::aDecimal($totalCompletado)],
                ),
                new Seccion(
                    titulo: 'Detalle por lote entregado',
                    descripcion: 'Los despachos anulados se listan para auditoría, pero no suman en los totales.',
                    columnas: [
                        ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha_hora', 'ancho' => 16],
                        ['clave' => 'despacho', 'titulo' => 'Despacho', 'ancho' => 20],
                        ['clave' => 'estado', 'titulo' => 'Estado', 'ancho' => 10],
                        ['clave' => 'cliente', 'titulo' => 'Cliente', 'ancho' => 24],
                        ['clave' => 'producto', 'titulo' => 'Producto', 'ancho' => 20],
                        ['clave' => 'lote', 'titulo' => 'Lote', 'ancho' => 24],
                        ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'cantidad', 'ancho' => 10],
                        ['clave' => 'precio', 'titulo' => 'Precio (Bs)', 'tipo' => 'moneda', 'ancho' => 11],
                        ['clave' => 'subtotal', 'titulo' => 'Subtotal (Bs)', 'tipo' => 'moneda', 'ancho' => 12],
                        ['clave' => 'responsable', 'titulo' => 'Responsable', 'ancho' => 16],
                    ],
                    filas: $filas,
                ),
            ],
            orientacion: 'landscape',
            parametros: $filtros,
            nombreArchivo: 'despachos',
        );
    }

    // =====================================================================
    //  Mermas y bajas (registro de no conformidades)
    // =====================================================================

    public function mermas(array $filtros, int $maxFilas): Reporte
    {
        $consulta = MovimientoInventario::query()
            ->whereIn('tipo_movimiento', [MovimientoInventario::MERMA_DESECHO, MovimientoInventario::BAJA_VENCIMIENTO])
            ->when($filtros['fecha_inicio'] ?? null, fn ($q, $f) => $q->where('created_at', '>=', Carbon::parse($f)->startOfDay()))
            ->when($filtros['fecha_fin'] ?? null, fn ($q, $f) => $q->where('created_at', '<=', Carbon::parse($f)->endOfDay()));

        $total = (clone $consulta)->count();
        if ($total > $maxFilas) {
            throw new InvalidArgumentException("El reporte tendría {$total} filas (máximo {$maxFilas}). Acote el período.");
        }

        $bajas = $consulta->with(['lote.insumo', 'lote.producto', 'user'])->orderBy('created_at')->orderBy('id')->get();

        $agrupado = [];
        $filas = $bajas->map(function (MovimientoInventario $m) use (&$agrupado) {
            $item = $m->lote?->insumo ?? $m->lote?->producto;
            $unidad = self::UNIDADES[$item?->unidad_medida] ?? '';
            $motivo = self::MOTIVOS_MERMA[$m->categoria_merma] ?? ($m->tipo_movimiento === MovimientoInventario::BAJA_VENCIMIENTO ? 'Vencimiento' : 'Sin categoría');

            $clave = "{$motivo}|{$item?->nombre}|{$unidad}";
            $agrupado[$clave] ??= ['motivo' => $motivo, 'item' => $item?->nombre, 'unidad' => $unidad, 'registros' => 0, 'cantidad' => 0];
            $agrupado[$clave]['registros']++;
            $agrupado[$clave]['cantidad'] += Cantidad::aMilesimas($m->cantidad);

            return [
                'fecha' => $m->created_at->toDateTimeString(),
                'motivo' => $motivo,
                'item' => $item?->nombre,
                'lote' => $m->lote?->codigo_lote,
                'cantidad' => $m->cantidad,
                'unidad' => $unidad,
                'observacion' => $m->motivo_observacion,
                'responsable' => $m->user?->name,
            ];
        })->all();

        $resumen = array_map(fn ($g) => [...$g, 'cantidad' => Cantidad::aDecimal($g['cantidad'])], array_values($agrupado));
        usort($resumen, fn ($a, $b) => [$a['motivo'], $a['item']] <=> [$b['motivo'], $b['item']]);

        return new Reporte(
            tipo: 'mermas',
            titulo: 'Registro de mermas y bajas',
            subtitulo: 'No conformidades de inventario · ' . $this->textoPeriodo($filtros),
            resumen: [
                ['Período', $this->textoPeriodo($filtros)],
                ['Registros', (string) count($filas)],
                ['Bajas por vencimiento', (string) $bajas->where('tipo_movimiento', MovimientoInventario::BAJA_VENCIMIENTO)->count()],
                ['Mermas por otras causas', (string) $bajas->where('tipo_movimiento', MovimientoInventario::MERMA_DESECHO)->count()],
            ],
            secciones: [
                new Seccion(
                    titulo: 'Resumen por motivo e ítem',
                    columnas: [
                        ['clave' => 'motivo', 'titulo' => 'Motivo', 'ancho' => 24],
                        ['clave' => 'item', 'titulo' => 'Ítem', 'ancho' => 24],
                        ['clave' => 'registros', 'titulo' => 'Registros', 'tipo' => 'entero', 'ancho' => 11],
                        ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'cantidad', 'ancho' => 11],
                        ['clave' => 'unidad', 'titulo' => 'Unidad', 'ancho' => 8],
                    ],
                    filas: $resumen,
                ),
                new Seccion(
                    titulo: 'Detalle de bajas',
                    columnas: [
                        ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha_hora', 'ancho' => 16],
                        ['clave' => 'motivo', 'titulo' => 'Motivo', 'ancho' => 18],
                        ['clave' => 'item', 'titulo' => 'Ítem', 'ancho' => 20],
                        ['clave' => 'lote', 'titulo' => 'Lote', 'ancho' => 24],
                        ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'cantidad', 'ancho' => 10],
                        ['clave' => 'unidad', 'titulo' => 'Unidad', 'ancho' => 7],
                        ['clave' => 'observacion', 'titulo' => 'Observación', 'ancho' => 40],
                        ['clave' => 'responsable', 'titulo' => 'Responsable', 'ancho' => 16],
                    ],
                    filas: $filas,
                ),
            ],
            conFirmas: true,
            orientacion: 'landscape',
            parametros: $filtros,
            nombreArchivo: 'mermas',
        );
    }

    // ---------- Internos ----------

    private function consultaKardex(array $f): Builder
    {
        return MovimientoInventario::query()
            ->when($f['lote_id'] ?? null, fn ($q, $id) => $q->where('lote_id', $id))
            ->when($f['tipo_item'] ?? null, function ($q, $tipo) use ($f) {
                $q->whereHas('lote', function ($l) use ($tipo, $f) {
                    $l->where('tipo_item', $tipo);
                    if (! empty($f['item_id'])) {
                        $l->where($tipo === Lote::TIPO_INSUMO ? 'insumo_id' : 'producto_id', $f['item_id']);
                    }
                });
            })
            ->when($f['tipo_movimiento'] ?? null, fn ($q, $t) => $q->where('tipo_movimiento', $t))
            ->when($f['fecha_inicio'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($f['fecha_fin'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()));
    }

    private function signo(MovimientoInventario $m): int
    {
        return in_array($m->tipo_movimiento, MovimientoInventario::TIPOS_ENTRADA, true) ? 1 : -1;
    }

    private function textoPeriodo(array $f): string
    {
        $desde = ! empty($f['fecha_inicio']) ? Carbon::parse($f['fecha_inicio'])->format('d/m/Y') : null;
        $hasta = ! empty($f['fecha_fin']) ? Carbon::parse($f['fecha_fin'])->format('d/m/Y') : null;

        return match (true) {
            $desde && $hasta => "Del {$desde} al {$hasta}",
            (bool) $desde => "Desde el {$desde}",
            (bool) $hasta => "Hasta el {$hasta}",
            default => 'Todo el historial',
        };
    }

    private function textoAlcance(array $f): string
    {
        if (! empty($f['lote_id'])) {
            $lote = Lote::with(['insumo', 'producto'])->find($f['lote_id']);

            return $lote ? "Lote {$lote->codigo_lote} · " . ($lote->insumo ?? $lote->producto)?->nombre : "Lote {$f['lote_id']}";
        }

        $item = ($f['tipo_item'] ?? null) === Lote::TIPO_PRODUCTO ? Producto::find($f['item_id']) : Insumo::find($f['item_id']);

        return $item ? "{$item->nombre} ({$item->codigo})" : 'Ítem';
    }
}
