<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de alertas de vencimiento con ventana preventiva dinámica:
 * la alerta se activa cuando los días restantes del lote son menores o
 * iguales al porcentaje_alerta_preventiva de su vida útil total.
 */
class AlertaService
{
    public const PORCENTAJE_POR_DEFECTO = 30.0;

    public function __construct(
        private readonly LoteService $loteService,
    ) {}

    /**
     * Evalúa todos los lotes vigentes con saldo.
     *
     * @return array{evaluados:int, activos:int, proximos_a_vencer:int, vencidos:int, reactivados:int, alertas_nuevas:int}
     */
    public function evaluarVencimientos(): array
    {
        $hoy = now()->startOfDay();
        $resumen = [
            'evaluados' => 0,
            'activos' => 0,
            'proximos_a_vencer' => 0,
            'vencidos' => 0,
            'reactivados' => 0,
            'alertas_nuevas' => 0,
        ];

        /** @var array<string, Insumo|Producto> Ítems cuyo stock cambió por lotes vencidos */
        $itemsAfectados = [];

        Lote::query()
            ->whereIn('estado', Lote::ESTADOS_DISPONIBLES)
            ->where('cantidad_actual', '>', 0)
            ->with(['insumo', 'producto'])
            ->chunkById(200, function ($lotes) use ($hoy, &$resumen, &$itemsAfectados) {
                foreach ($lotes as $lote) {
                    [$resultado, $alertaNueva] = DB::transaction(fn () => $this->clasificarLote($lote, $hoy));

                    $resumen['evaluados']++;
                    $resumen[$resultado]++;
                    $resumen['alertas_nuevas'] += $alertaNueva ? 1 : 0;

                    if ($resultado === 'vencidos' && ($item = $lote->item())) {
                        $itemsAfectados[$lote->tipo_item . ':' . $item->id] = $item;
                    }
                }
            });

        // Un lote vencido deja de ser stock utilizable
        foreach ($itemsAfectados as $item) {
            DB::transaction(function () use ($item) {
                $this->loteService->recalcularStock($item);
                $this->loteService->evaluarStockMinimo($item);
            });
        }

        return $resumen;
    }

    /**
     * Evalúa un solo lote al momento (p. ej. al recibir una compra que ya
     * llega dentro de su ventana preventiva). Devuelve el estado resultante.
     */
    public function evaluarLote(Lote $lote): string
    {
        $this->clasificarLote($lote, now()->startOfDay());

        return $lote->estado;
    }

    /**
     * Calcula la ventana preventiva de un lote en días.
     * Ej.: vida útil 180 días al 30 % => 54 días.
     */
    public function diasVentanaPreventiva(Lote $lote): int
    {
        $vidaUtil = $this->dias($lote->fecha_fabricacion, $lote->fecha_vencimiento);
        $porcentaje = (float) ($lote->item()?->porcentaje_alerta_preventiva ?? self::PORCENTAJE_POR_DEFECTO);

        return (int) ceil($vidaUtil * $porcentaje / 100);
    }

    // ---------- Internos ----------

    /**
     * @return array{0: string, 1: bool} [clave del resumen, si se creó una alerta nueva]
     */
    private function clasificarLote(Lote $lote, Carbon $hoy): array
    {
        $vencimiento = $lote->fecha_vencimiento->copy()->startOfDay();
        $item = $lote->item();

        // ---- Vencido: bloqueo automático (sale de los lotes "disponibles") ----
        if ($vencimiento->lt($hoy)) {
            $lote->update(['estado' => Lote::ESTADO_VENCIDO]);

            $alerta = $this->registrarAlerta($lote, Alerta::VENCIMIENTO_CRITICO, Alerta::PRIORIDAD_ALTA, sprintf(
                'Lote %s de "%s" VENCIDO el %s con %s %s en stock. Salida bloqueada: registrar baja por vencimiento.',
                $lote->codigo_lote,
                $item?->nombre,
                $vencimiento->toDateString(),
                $lote->cantidad_actual,
                $item?->unidad_medida,
            ));

            return ['vencidos', $alerta->wasRecentlyCreated];
        }

        $diasRestantes = $this->dias($hoy, $vencimiento);

        // ---- Dentro de la ventana preventiva ----
        if ($diasRestantes <= $this->diasVentanaPreventiva($lote)) {
            if ($lote->estado !== Lote::ESTADO_PROXIMO_A_VENCER) {
                $lote->update(['estado' => Lote::ESTADO_PROXIMO_A_VENCER]);
            }

            $alerta = $this->registrarAlerta(
                $lote,
                Alerta::PREVENTIVA_VENCIMIENTO,
                $diasRestantes <= 1 ? Alerta::PRIORIDAD_ALTA : Alerta::PRIORIDAD_MEDIA,
                sprintf(
                    'Lote %s de "%s" %s (%s). Quedan %s %s: priorizar su %s según FEFO.',
                    $lote->codigo_lote,
                    $item?->nombre,
                    $diasRestantes === 0 ? 'vence HOY' : "vence en {$diasRestantes} día(s)",
                    $vencimiento->toDateString(),
                    $lote->cantidad_actual,
                    $item?->unidad_medida,
                    $lote->tipo_item === Lote::TIPO_INSUMO ? 'consumo en producción' : 'despacho',
                ),
            );

            return ['proximos_a_vencer', $alerta->wasRecentlyCreated];
        }

        // ---- Fuera de la ventana (p. ej. si se redujo el porcentaje) ----
        if ($lote->estado === Lote::ESTADO_PROXIMO_A_VENCER) {
            $lote->update(['estado' => Lote::ESTADO_ACTIVO]);

            return ['reactivados', false];
        }

        return ['activos', false];
    }

    /** Una sola alerta abierta (no leída) por lote y tipo; se actualiza a diario */
    private function registrarAlerta(Lote $lote, string $tipo, string $prioridad, string $mensaje): Alerta
    {
        return Alerta::updateOrCreate(
            [
                'lote_id' => $lote->id,
                'tipo_alerta' => $tipo,
                'leida' => false,
            ],
            [
                'insumo_id' => $lote->insumo_id,
                'producto_id' => $lote->producto_id,
                'mensaje' => $mensaje,
                'nivel_prioridad' => $prioridad,
            ],
        );
    }

    /** Días enteros entre dos fechas (compatible con Carbon 2 y 3) */
    private function dias(Carbon $desde, Carbon $hasta): int
    {
        return (int) round(abs($desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay())));
    }
}
