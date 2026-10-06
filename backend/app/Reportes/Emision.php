<?php

namespace App\Reportes;

use Illuminate\Support\Carbon;

/** Datos de la emisión impresos en el documento */
final class Emision
{
    public function __construct(
        public readonly string $codigo,
        public readonly Carbon $fecha,
        public readonly string $usuario,
        public readonly array $empresa,
    ) {}
}
