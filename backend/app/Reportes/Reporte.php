<?php

namespace App\Reportes;

/**
 * Contenido de un reporte, independiente del formato de salida.
 * El mismo objeto se convierte en PDF (PdfRenderer) o en Excel (ExcelRenderer).
 */
final class Reporte
{
    /**
     * @param  array<int, array{0:string, 1:string}>  $resumen   Pares [etiqueta, valor] del encabezado
     * @param  array<int, Seccion>  $secciones
     * @param  array<int, string>  $notas
     * @param  array<string, mixed>  $parametros  Filtros usados (se registran con la emisión)
     */
    public function __construct(
        public readonly string $tipo,
        public readonly string $titulo,
        public readonly string $subtitulo = '',
        public readonly array $resumen = [],
        public readonly array $secciones = [],
        public readonly array $notas = [],
        public readonly bool $conFirmas = false,
        public readonly string $orientacion = 'portrait',
        public readonly array $parametros = [],
        public readonly string $nombreArchivo = 'reporte',
    ) {}

    public function totalFilas(): int
    {
        return array_sum(array_map(fn (Seccion $s) => count($s->filas), $this->secciones));
    }

    /** Contenido serializado para calcular la huella de integridad */
    public function contenido(): string
    {
        return json_encode([$this->tipo, $this->resumen, $this->secciones, $this->notas], JSON_UNESCAPED_UNICODE);
    }
}
