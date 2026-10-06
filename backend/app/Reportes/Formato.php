<?php

namespace App\Reportes;

use Illuminate\Support\Carbon;

/** Formato de valores para el PDF (convención boliviana: 1.234,5) */
final class Formato
{
    public static function celda(?string $tipo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match ($tipo) {
            'cantidad' => self::cantidad($valor),
            'moneda' => number_format((float) $valor, 2, ',', '.'),
            'entero' => number_format((int) $valor, 0, ',', '.'),
            'fecha' => Carbon::parse($valor)->format('d/m/Y'),
            'fecha_hora' => Carbon::parse($valor)->format('d/m/Y H:i'),
            default => (string) $valor,
        };
    }

    /** 3 decimales sin ceros sobrantes: 250,000 -> 250 ; 12,500 -> 12,5 */
    public static function cantidad(mixed $valor): string
    {
        $texto = number_format((float) $valor, 3, ',', '.');

        return rtrim(rtrim($texto, '0'), ',');
    }

    public static function esNumerico(?string $tipo): bool
    {
        return in_array($tipo, ['cantidad', 'moneda', 'entero'], true);
    }
}
