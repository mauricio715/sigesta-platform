<?php

namespace App\Http\Controllers\Api;

use App\Models\Despacho;
use App\Models\Lote;
use App\Models\ReporteEmitido;
use App\Reportes\Emision;
use App\Reportes\ExcelRenderer;
use App\Reportes\PdfRenderer;
use App\Reportes\Reporte;
use App\Services\ReporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reportes descargables. Cada emisión queda registrada con su huella SHA-256
 * y un código de verificación impreso en el documento.
 */
class ReporteController extends ApiController
{
    private const MIME = [
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly ReporteService $reportes,
        private readonly PdfRenderer $pdf,
        private readonly ExcelRenderer $excel,
    ) {}

    /** GET /reportes/trazabilidad/{lote}?formato=pdf|xlsx */
    public function trazabilidad(Request $request, Lote $lote): Response
    {
        $formato = $this->formato($request, 'pdf');

        if ($lote->estado === Lote::ESTADO_ANULADO) {
            return $this->error("El lote {$lote->codigo_lote} está anulado: no tiene trazabilidad que reportar.", 422);
        }

        return $this->emitir($request, $this->reportes->trazabilidad($lote), $formato);
    }

    /** GET /reportes/inventario?formato=pdf|xlsx */
    public function inventario(Request $request): Response
    {
        return $this->emitir($request, $this->reportes->inventario(), $this->formato($request, 'pdf'));
    }

    /** GET /reportes/kardex?formato=&lote_id=&tipo_item=&item_id=&tipo_movimiento=&fecha_inicio=&fecha_fin= */
    public function kardex(Request $request): Response
    {
        $formato = $this->formato($request, 'xlsx');
        $filtros = $request->validate([
            'lote_id' => ['nullable', 'integer', 'min:1'],
            'tipo_item' => ['nullable', 'required_with:item_id', Rule::in([Lote::TIPO_INSUMO, Lote::TIPO_PRODUCTO])],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'tipo_movimiento' => ['nullable', Rule::in(array_keys(ReporteService::TIPOS_MOVIMIENTO))],
            ...$this->reglasPeriodo($request),
        ]);

        return $this->conLimite($request, $formato, fn ($max) => $this->reportes->kardex($this->limpiar($filtros), $max));
    }

    /** GET /reportes/despachos?formato=&cliente_id=&estado=&fecha_inicio=&fecha_fin= */
    public function despachos(Request $request): Response
    {
        $formato = $this->formato($request, 'xlsx');
        $filtros = $request->validate([
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', Rule::in([Despacho::ESTADO_COMPLETADO, Despacho::ESTADO_ANULADO])],
            ...$this->reglasPeriodo($request),
        ]);

        return $this->conLimite($request, $formato, fn ($max) => $this->reportes->despachos($this->limpiar($filtros), $max));
    }

    /** GET /reportes/mermas?formato=&fecha_inicio=&fecha_fin= */
    public function mermas(Request $request): Response
    {
        $formato = $this->formato($request, 'pdf');
        $filtros = $request->validate($this->reglasPeriodo($request));

        return $this->conLimite($request, $formato, fn ($max) => $this->reportes->mermas($this->limpiar($filtros), $max));
    }

    /** GET /reportes/verificar/{codigo} — ¿este documento lo emitió SI-GESTA? */
    public function verificar(string $codigo): JsonResponse
    {
        $normalizado = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $codigo));
        $legible = implode('-', str_split($normalizado, 4));

        $emitido = ReporteEmitido::with('user')->where('codigo', $legible)->first();

        if ($emitido === null) {
            return $this->error('No existe un reporte emitido con ese código. El documento podría no ser auténtico.', 404);
        }

        return $this->success([
            'codigo' => $emitido->codigo,
            'tipo' => $emitido->tipo,
            'titulo' => $emitido->titulo,
            'formato' => $emitido->formato,
            'parametros' => $emitido->parametros,
            'emitido_por' => $emitido->user->name,
            'emitido_at' => $emitido->created_at->toDateTimeString(),
        ], 'Reporte auténtico: fue emitido por SI-GESTA.');
    }

    // ---------- Internos ----------

    private function emitir(Request $request, Reporte $reporte, string $formato): Response
    {
        $fecha = now();
        $usuario = $request->user();

        $huella = hash('sha256', implode('|', [
            $reporte->contenido(),
            json_encode($reporte->parametros),
            $formato,
            $fecha->toIso8601String(),
            $usuario->id,
            bin2hex(random_bytes(8)), // dos emisiones idénticas en el mismo segundo no comparten código
        ]));
        $codigo = implode('-', str_split(strtoupper(substr($huella, 0, 12)), 4));

        ReporteEmitido::create([
            'codigo' => $codigo,
            'huella' => $huella,
            'tipo' => $reporte->tipo,
            'formato' => $formato,
            'titulo' => mb_substr("{$reporte->titulo} · {$reporte->subtitulo}", 0, 200),
            'parametros' => $reporte->parametros,
            'user_id' => $usuario->id,
        ]);

        $emision = new Emision($codigo, $fecha, $usuario->name, config('sigesta.empresa'));
        $contenido = $formato === 'pdf' ? $this->pdf->render($reporte, $emision) : $this->excel->render($reporte, $emision);
        $archivo = sprintf('%s_%s.%s', preg_replace('/[^A-Za-z0-9_\-]/', '_', $reporte->nombreArchivo), $fecha->format('Ymd_His'), $formato);

        return response($contenido, 200, [
            'Content-Type' => self::MIME[$formato],
            'Content-Disposition' => "attachment; filename=\"{$archivo}\"",
            'X-Codigo-Verificacion' => $codigo,
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Reportes con límite de filas según el formato */
    private function conLimite(Request $request, string $formato, callable $construir): Response
    {
        $max = (int) config("sigesta.reportes.max_filas_{$formato}");

        try {
            $reporte = $construir($max);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->emitir($request, $reporte, $formato);
    }

    private function formato(Request $request, string $porDefecto): string
    {
        $request->validate(['formato' => ['nullable', Rule::in(['pdf', 'xlsx'])]], [
            'formato.in' => 'Formato no válido. Opciones: pdf, xlsx.',
        ]);

        return $request->query('formato', $porDefecto);
    }

    private function reglasPeriodo(Request $request): array
    {
        $fin = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('fecha_inicio')) {
            $fin[] = 'after_or_equal:fecha_inicio';
        }

        return [
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => $fin,
        ];
    }

    private function limpiar(array $filtros): array
    {
        return array_filter($filtros, fn ($v) => $v !== null && $v !== '');
    }
}
