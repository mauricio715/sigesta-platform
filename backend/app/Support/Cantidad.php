<?php

namespace App\Support;

/**
 * Aritmética exacta de cantidades con 3 decimales (columnas decimal(12,3)).
 * Internamente todo se opera como enteros en milésimas para evitar
 * los errores acumulados de float.
 */
final class Cantidad
{
    public const ESCALA = 1000;

    public static function aMilesimas(float|int|string|null $valor): int
    {
        return (int) round(((float) $valor) * self::ESCALA);
    }

    public static function aDecimal(int $milesimas): string
    {
        return number_format($milesimas / self::ESCALA, 3, '.', '');
    }

    public static function aFloat(int $milesimas): float
    {
        return $milesimas / self::ESCALA;
    }
}
