<?php

namespace App\Reportes;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel con una hoja "Resumen" y una hoja por sección. Los números y fechas
 * se guardan como valores reales (no texto) para poder filtrar y sumar.
 */
final class ExcelRenderer
{
    private const PETROLEO = 'FF0E5A73';
    private const FILA_ENCABEZADO = 5;

    private const FORMATOS = [
        'cantidad' => '#,##0.###',
        'moneda' => '#,##0.00',
        'entero' => '#,##0',
        'fecha' => 'dd/mm/yyyy',
        'fecha_hora' => 'dd/mm/yyyy hh:mm',
    ];

    public function render(Reporte $reporte, Emision $emision): string
    {
        $libro = new Spreadsheet();
        $libro->getProperties()
            ->setCreator('SI-GESTA')
            ->setTitle($reporte->titulo)
            ->setSubject($reporte->subtitulo)
            ->setDescription("Código de verificación {$emision->codigo}");

        $this->hojaResumen($libro->getActiveSheet(), $reporte, $emision);

        $nombres = ['Resumen' => true];
        foreach ($reporte->secciones as $seccion) {
            $hoja = $libro->createSheet();
            $hoja->setTitle($this->nombreHoja($seccion->titulo, $nombres));
            $this->hojaSeccion($hoja, $seccion, $reporte, $emision);
        }

        $libro->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($libro))->save('php://output');

        return (string) ob_get_clean();
    }

    private function hojaResumen(Worksheet $hoja, Reporte $reporte, Emision $emision): void
    {
        $hoja->setTitle('Resumen');
        $hoja->setCellValue('A1', $reporte->titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $hoja->setCellValue('A2', $reporte->subtitulo);

        $filas = [
            ['Empresa', $emision->empresa['nombre'] ?? ''],
            ['Emitido por', $emision->usuario],
            ['Fecha de emisión', $emision->fecha->format('d/m/Y H:i')],
            ['Código de verificación', $emision->codigo],
            ['', ''],
            ...$reporte->resumen,
        ];

        $fila = 4;
        foreach ($filas as [$etiqueta, $valor]) {
            $hoja->setCellValue("A{$fila}", $etiqueta);
            $hoja->setCellValueExplicit("B{$fila}", (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
            $fila++;
        }

        if ($reporte->notas !== []) {
            $fila++;
            $hoja->setCellValue("A{$fila}", 'Notas');
            $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
            foreach ($reporte->notas as $nota) {
                $fila++;
                $hoja->setCellValue("A{$fila}", "• {$nota}");
            }
        }

        $hoja->getColumnDimension('A')->setWidth(28);
        $hoja->getColumnDimension('B')->setWidth(60);
    }

    private function hojaSeccion(Worksheet $hoja, Seccion $seccion, Reporte $reporte, Emision $emision): void
    {
        $hoja->setCellValue('A1', $seccion->titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $hoja->setCellValue('A2', "{$reporte->titulo} · {$emision->empresa['nombre']}");
        $hoja->setCellValue('A3', "Emitido el {$emision->fecha->format('d/m/Y H:i')} por {$emision->usuario} · Código {$emision->codigo}");
        $hoja->getStyle('A2:A3')->getFont()->setSize(9)->getColor()->setARGB('FF5B6B7C');

        $columnas = array_values($seccion->columnas);
        $ultima = Coordinate::stringFromColumnIndex(count($columnas));
        $f = self::FILA_ENCABEZADO;

        foreach ($columnas as $i => $columna) {
            $letra = Coordinate::stringFromColumnIndex($i + 1);
            $hoja->setCellValue("{$letra}{$f}", $columna['titulo']);
            $hoja->getColumnDimension($letra)->setWidth($columna['ancho'] ?? 16);
        }

        $hoja->getStyle("A{$f}:{$ultima}{$f}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::PETROLEO]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $fila = $f;
        foreach ($seccion->filas as $datos) {
            $fila++;
            $this->escribirFila($hoja, $columnas, $datos, $fila);
        }

        if ($seccion->filas === []) {
            $hoja->setCellValue('A' . ($f + 1), $seccion->vacio);
            $fila = $f + 1;
        } else {
            $hoja->setAutoFilter("A{$f}:{$ultima}{$fila}");
        }

        if ($seccion->totales !== null && $seccion->filas !== []) {
            $fila++;
            $this->escribirFila($hoja, $columnas, $seccion->totales, $fila);
            $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM]],
            ]);
        }

        $hoja->freezePane('A' . ($f + 1));
    }

    private function escribirFila(Worksheet $hoja, array $columnas, array $datos, int $fila): void
    {
        foreach ($columnas as $i => $columna) {
            $valor = $datos[$columna['clave']] ?? null;
            if ($valor === null || $valor === '') {
                continue;
            }

            $celda = Coordinate::stringFromColumnIndex($i + 1) . $fila;
            $tipo = $columna['tipo'] ?? 'texto';

            if (in_array($tipo, ['fecha', 'fecha_hora'], true)) {
                $hoja->setCellValue($celda, FechaExcel::PHPToExcel(Carbon::parse($valor)));
            } elseif (Formato::esNumerico($tipo) && is_numeric($valor)) {
                $hoja->setCellValue($celda, (float) $valor);
            } else {
                // Texto explícito: códigos como "0012" o "=algo" no se interpretan
                $hoja->setCellValueExplicit($celda, (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                continue;
            }

            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(self::FORMATOS[$tipo]);
        }
    }

    /** Excel exige nombres de hoja únicos, de hasta 31 caracteres y sin : \ / ? * [ ] */
    private function nombreHoja(string $titulo, array &$usados): string
    {
        $base = mb_substr(trim(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $titulo)), 0, 28) ?: 'Hoja';
        $nombre = $base;
        $n = 2;
        while (isset($usados[$nombre])) {
            $nombre = mb_substr($base, 0, 26) . " {$n}";
            $n++;
        }
        $usados[$nombre] = true;

        return $nombre;
    }
}
