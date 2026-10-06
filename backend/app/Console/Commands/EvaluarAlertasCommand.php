<?php

namespace App\Console\Commands;

use App\Services\AlertaService;
use Illuminate\Console\Command;

class EvaluarAlertasCommand extends Command
{
    protected $signature = 'sigesta:evaluar-alertas';

    protected $description = 'Evalúa el vencimiento de los lotes vigentes con la ventana preventiva dinámica (% de vida útil), actualiza sus estados y genera alertas';

    public function handle(AlertaService $alertas): int
    {
        $this->info('SI-GESTA · Evaluando vencimientos de lotes...');

        $resumen = $alertas->evaluarVencimientos();

        $this->table(['Métrica', 'Cantidad'], [
            ['Lotes evaluados', $resumen['evaluados']],
            ['Activos', $resumen['activos']],
            ['Próximos a vencer', $resumen['proximos_a_vencer']],
            ['Vencidos (bloqueados)', $resumen['vencidos']],
            ['Reactivados', $resumen['reactivados']],
            ['Alertas nuevas', $resumen['alertas_nuevas']],
        ]);

        if ($resumen['vencidos'] > 0) {
            $this->warn("Atención: {$resumen['vencidos']} lote(s) vencido(s) requieren registro de merma/desecho.");
        }

        return self::SUCCESS;
    }
}
