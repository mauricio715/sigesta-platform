<?php

namespace App\Reportes;

/**
 * Tabla de un reporte. Columnas:
 *   ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'cantidad', 'ancho' => 12]
 * Tipos: texto | cantidad | moneda | entero | fecha | fecha_hora
 */
final class Seccion
{
    /**
     * @param  array<int, array{clave:string, titulo:string, tipo?:string, ancho?:int}>  $columnas
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, mixed>|null  $totales  Valores por clave de columna (fila final en negrita)
     */
    public function __construct(
        public readonly string $titulo,
        public readonly array $columnas,
        public readonly array $filas,
        public readonly ?array $totales = null,
        public readonly string $vacio = 'Sin registros para los criterios seleccionados.',
        public readonly ?string $descripcion = null,
    ) {}
}
