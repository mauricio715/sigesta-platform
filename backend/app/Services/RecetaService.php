<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Receta;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Versionado de recetas (BOM). Una receta usada en producción nunca se edita:
 * los cambios de fórmula se registran como una versión nueva, para que la
 * trazabilidad de las órdenes históricas siga siendo exacta.
 * Cada producto tiene como máximo una receta activa.
 */
class RecetaService
{
    /**
     * @param  array{
     *     producto_id: int,
     *     nombre_receta: string,
     *     rendimiento_base: float|int|string,
     *     observaciones?: ?string,
     *     activa?: bool,
     *     insumos: array<int, array{insumo_id:int, cantidad_requerida: float|int|string}>,
     * }  $data
     */
    public function crearVersion(array $data): Receta
    {
        $insumos = collect($data['insumos'] ?? []);

        if ($insumos->isEmpty()) {
            throw new InvalidArgumentException('La receta debe incluir al menos un insumo.');
        }

        if ($insumos->pluck('insumo_id')->map(fn ($id) => (int) $id)->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Un insumo no puede repetirse dentro de la misma receta.');
        }

        if (Cantidad::aMilesimas($data['rendimiento_base'] ?? 0) <= 0) {
            throw new InvalidArgumentException('El rendimiento base debe ser mayor a cero.');
        }

        if ($insumos->contains(fn ($i) => Cantidad::aMilesimas($i['cantidad_requerida'] ?? 0) <= 0)) {
            throw new InvalidArgumentException('Todas las cantidades de la receta deben ser mayores a cero.');
        }

        $activa = (bool) ($data['activa'] ?? true);

        return DB::transaction(function () use ($data, $insumos, $activa) {
            // Serializa cambios de recetas del mismo producto
            $producto = Producto::query()->lockForUpdate()->findOrFail((int) $data['producto_id']);

            if ($activa) {
                $producto->recetas()->activas()->update(['activa' => false]);
            }

            $receta = Receta::create([
                'producto_id' => $producto->id,
                'nombre_receta' => trim($data['nombre_receta']),
                'rendimiento_base' => Cantidad::aDecimal(Cantidad::aMilesimas($data['rendimiento_base'])),
                'observaciones' => $data['observaciones'] ?? null,
                'activa' => $activa,
            ]);

            // El orden de la fórmula respeta el orden enviado
            foreach ($insumos as $item) {
                $receta->insumos()->attach((int) $item['insumo_id'], [
                    'cantidad_requerida' => Cantidad::aDecimal(Cantidad::aMilesimas($item['cantidad_requerida'])),
                ]);
            }

            return $receta->load(['producto', 'insumos']);
        });
    }

    /** Activar una versión desactiva automáticamente las demás del producto */
    public function cambiarEstado(Receta $receta, bool $activa): Receta
    {
        return DB::transaction(function () use ($receta, $activa) {
            Producto::query()->lockForUpdate()->findOrFail($receta->producto_id);

            if ($activa) {
                Receta::query()
                    ->where('producto_id', $receta->producto_id)
                    ->where('id', '!=', $receta->id)
                    ->where('activa', true)
                    ->update(['activa' => false]);
            }

            $receta->update(['activa' => $activa]);

            return $receta->load(['producto', 'insumos']);
        });
    }
}
