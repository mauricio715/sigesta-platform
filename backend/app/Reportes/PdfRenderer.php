<?php

namespace App\Reportes;

use Barryvdh\DomPDF\Facade\Pdf;

final class PdfRenderer
{
    /** HTML del documento (se usa también en las pruebas) */
    public function html(Reporte $reporte, Emision $emision): string
    {
        return view('reportes.documento', [
            'reporte' => $reporte,
            'emision' => $emision,
        ])->render();
    }

    public function render(Reporte $reporte, Emision $emision): string
    {
        $pdf = Pdf::loadHTML($this->html($reporte, $emision))
            ->setPaper('a4', $reporte->orientacion)
            ->setOption('isRemoteEnabled', false)          // nunca descargar recursos externos
            ->setOption('defaultFont', 'DejaVu Sans');     // soporta tildes y ñ

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        // Numeración "Página X de Y" (se agrega después de conocer el total de páginas)
        $lienzo = $dompdf->getCanvas();
        $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $lienzo->page_text(
            $lienzo->get_width() - 105,
            $lienzo->get_height() - 30,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $fuente,
            7.5,
            [0.36, 0.42, 0.49],
        );

        return $dompdf->output();
    }
}
