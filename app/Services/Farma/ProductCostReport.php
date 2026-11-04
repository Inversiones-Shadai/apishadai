<?php

namespace App\Services\Farma;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductCostReport
{
    public const MAX_PURCHASES = 1000;

    public function report(array $filters): array
    {
        return DB::transaction(function () use ($filters): array {
            $product = DB::table('productos')->where('id', $filters['producto_id'])->first(['id', 'codigo', 'descripcion']);
            abort_unless($product, 404);
            $query = DB::table('compras as c')->whereNotIn('c.estado', ['borrador', 'eliminada', 'anulada'])
                ->whereExists(fn ($detail) => $detail->selectRaw('1')->from('compra_detalle as d')
                    ->whereColumn('d.compra', 'c.id')->where('d.producto', $product->id));
            if (! empty($filters['fecha_desde'])) {
                $query->where('c.created_at', '>=', $filters['fecha_desde'].' 00:00:00');
            }
            if (! empty($filters['fecha_hasta'])) {
                $query->where('c.created_at', '<', CarbonImmutable::parse($filters['fecha_hasta'], 'America/Tegucigalpa')->addDay()->startOfDay()->toDateTimeString());
            }
            if (! empty($filters['proveedor_id'])) {
                $query->where('c.proveedor', $filters['proveedor_id']);
            }
            $ids = $query->orderBy('c.created_at')->orderBy('c.id')->limit(self::MAX_PURCHASES + 1)->pluck('c.id');
            if ($ids->count() > self::MAX_PURCHASES) {
                throw ValidationException::withMessages(['fecha_desde' => 'El período supera las 1,000 compras. Reduce el rango de fechas para consultar el reporte completo.']);
            }
            $details = DB::table('compra_detalle as d')->join('compras as c', 'c.id', '=', 'd.compra')
                ->leftJoin('proveedores as p', 'p.id', '=', 'c.proveedor')
                ->where('d.producto', $product->id)->whereIn('c.id', $ids)
                ->orderBy('c.created_at')->orderBy('c.id')->orderBy('d.id')
                ->get(['c.id as compra_id', 'c.nro', 'c.created_at as fecha', 'c.estado', 'p.nombre as proveedor',
                    'd.id', 'd.lote', 'd.cantidad', 'd.costo', 'd.descuento', 'd.isv', 'd.total', 'd.es_bonificacion']);
            $previous = null;
            $purchases = [];
            foreach ($details->groupBy('compra_id') as $lines) {
                $purchase = $this->purchase($lines);
                $cost = $purchase['costo_unitario'];
                $purchase['variacion'] = $cost !== null && $previous !== null ? round($cost - $previous, 4) : null;
                $purchase['variacion_pct'] = $purchase['variacion'] !== null && $previous > 0 ? round($purchase['variacion'] / $previous * 100, 2) : null;
                $purchase['tendencia'] = $purchase['variacion'] === null ? 'sin_comparacion' : ($purchase['variacion'] > 0 ? 'sube' : ($purchase['variacion'] < 0 ? 'baja' : 'igual'));
                if (! $purchase['solo_bonificacion']) {
                    $previous = $cost;
                }
                $purchases[] = $purchase;
            }
            $costs = array_values(array_filter(array_column($purchases, 'costo_unitario'), fn ($cost): bool => $cost !== null));
            $first = $costs[0] ?? null;
            $last = $costs === [] ? null : $costs[array_key_last($costs)];

            return ['producto' => (array) $product, 'compras' => $purchases, 'resumen' => [
                'compras' => count($purchases), 'comparables' => count($costs), 'costo_inicial' => $first, 'costo_reciente' => $last,
                'costo_minimo' => $costs === [] ? null : min($costs), 'costo_maximo' => $costs === [] ? null : max($costs),
                'variacion' => $first !== null && $last !== null ? round($last - $first, 4) : null,
                'variacion_pct' => $first > 0 && $last !== null ? round(($last - $first) / $first * 100, 2) : null,
                'bonificaciones' => array_sum(array_column($purchases, 'bonificaciones')),
            ]];
        });
    }

    private function purchase(Collection $lines): array
    {
        $header = $lines->first();
        $normal = $lines->filter(fn ($line): bool => ! $line->es_bonificacion);
        $quantity = 0;
        $gross = $discount = $tax = $total = 0.0;
        $costKnown = $finalKnown = $discountKnown = $taxKnown = true;
        foreach ($normal as $line) {
            if (! is_numeric($line->cantidad) || $line->cantidad <= 0) {
                $costKnown = $finalKnown = $discountKnown = $taxKnown = false;

                continue;
            }
            $quantity += (int) $line->cantidad;
            $costValid = is_numeric($line->costo) && $line->costo >= 0;
            $totalValid = is_numeric($line->total) && $line->total >= 0;
            $discountValid = is_numeric($line->descuento) && $line->descuento >= 0;
            $costKnown = $costKnown && $costValid;
            $finalKnown = $finalKnown && $totalValid;
            $discountKnown = $discountKnown && $discountValid;
            $lineGross = $costValid ? round((float) $line->costo * $line->cantidad, 2) : 0;
            $lineDiscount = $discountValid ? round((float) $line->descuento * $line->cantidad, 2) : 0;
            $gross += $lineGross;
            $discount += $lineDiscount;
            $total += $totalValid ? (float) $line->total : 0;
            // Recover historical tax from the saved line total, never today's configured rate.
            $lineTax = $totalValid ? round((float) $line->total - $lineGross + $lineDiscount, 2) : null;
            $validTax = $costValid && $totalValid && $discountValid && $line->isv !== null && $lineTax >= 0
                && ((bool) $line->isv || abs($lineTax) < 0.01);
            $taxKnown = $taxKnown && $validTax;
            $tax += $validTax ? $lineTax : 0;
        }
        $flags = $lines->map(fn ($line): string => $line->isv === null ? 'sin_dato' : ((bool) $line->isv ? 'si' : 'no'))->unique()->values();
        $taxStatus = $flags->contains('sin_dato') ? 'sin_dato' : ($flags->count() > 1 ? 'mixto' : ($flags->first() ?? 'sin_dato'));

        return ['id' => (int) $header->compra_id, 'factura' => (string) $header->nro, 'fecha' => $header->fecha,
            'proveedor' => $header->proveedor, 'estado' => $header->estado, 'cantidad' => $quantity,
            'bonificaciones' => (int) $lines->filter(fn ($line): bool => (bool) $line->es_bonificacion)->sum('cantidad'),
            'solo_bonificacion' => $normal->isEmpty(), 'lotes' => $lines->pluck('lote')->unique()->values()->all(),
            'costo_unitario' => $costKnown && $quantity > 0 ? round($gross / $quantity, 4) : null,
            'descuento_unitario' => $discountKnown && $quantity > 0 ? round($discount / $quantity, 4) : null,
            'costo_final_unitario' => $finalKnown && $quantity > 0 ? round($total / $quantity, 4) : null,
            'isv_estado' => $taxStatus, 'isv_unitario' => $taxKnown && $quantity > 0 ? round($tax / $quantity, 4) : null,
        ];
    }
}
