<?php

namespace App\Services\Farma;

use App\Http\Resources\Farma\LotResource;
use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use App\Models\Farma\Producto;
use App\Models\Farma\ProdUnidad;
use DomainException;
use Illuminate\Support\Facades\DB;

class TransferProductConversion
{
    /** @return array<string, mixed> */
    public function options(int $lotId, bool $compact = false): array
    {
        $lot = Lote::with('productoDetalle.unidadDetalle')->findOrFail($lotId);
        $parent = $lot->productoDetalle;
        $factor = $this->units($parent?->unidadDetalle);
        $childrenQuery = $parent->hijos()->with('unidadDetalle')->orderBy('descripcion');
        if ($compact) {
            $childrenQuery->whereHas('unidadDetalle', fn ($unit) => $unit->where('cantidad_c', '>', 0)->where('cantidad_v', '>', 0)
                ->whereRaw('cantidad_c % cantidad_v = 0')->whereRaw('? % cantidad_v = 0', [$factor]));
        }
        $total = $compact ? (clone $childrenQuery)->count() : null;
        $children = ($compact ? $childrenQuery->limit(10) : $childrenQuery)->get();

        return [
            'lote' => $lotId,
            'disponible' => (int) $lot->cantidad,
            'unidades_por_padre' => $factor,
            'hijos_total' => $total ?? $children->count(),
            'hijos' => $children->filter(function (Producto $child) use ($factor): bool {
                try {
                    $this->units($child->unidadDetalle);

                    return $factor % (int) $child->unidadDetalle->cantidad_v === 0;
                } catch (DomainException) {
                    return false;
                }
            })->map(fn (Producto $child): array => [
                'id' => (int) $child->id, 'codigo' => $child->codigo, 'descripcion' => $child->descripcion,
            ])->values()->all(),
        ];
    }

    /** @param array{request_id: string, lote_padre: int, producto_hijo: int, cantidad: int} $data
     * @return array<string, mixed>
     */
    public function convert(int $stockId, int $userId, array $data): array
    {
        return DB::transaction(function () use ($stockId, $userId, $data): array {
            // Share the relation lock with catalog edits and other conversion channels.
            DB::table('producto_relacion_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            $previous = DB::table('transferencia_conversiones')->where('request_id', $data['request_id'])->lockForUpdate()->first();
            if ($previous !== null) {
                foreach (['usuario' => $userId, 'stock' => $stockId, 'lote_padre' => $data['lote_padre'], 'producto_hijo' => $data['producto_hijo'], 'cantidad' => $data['cantidad']] as $field => $value) {
                    if ((int) $previous->$field !== $value) {
                        throw new DomainException('La solicitud de conversión ya se utilizó con otros datos.');
                    }
                }

                return json_decode($previous->respuesta, true, 512, JSON_THROW_ON_ERROR);
            }

            $lot = Lote::query()->lockForUpdate()->find($data['lote_padre']);
            $child = Producto::query()->lockForUpdate()->find($data['producto_hijo']);
            if ($lot === null || $child === null || (int) $child->producto_padre_id !== (int) $lot->producto || (int) $child->id === (int) $lot->producto) {
                throw new DomainException('El producto hijo debe pertenecer al padre del lote seleccionado.');
            }
            $parent = Producto::query()->lockForUpdate()->findOrFail($lot->producto);
            $factor = $this->units(ProdUnidad::query()->lockForUpdate()->find($parent->unidad));
            $childUnit = ProdUnidad::query()->lockForUpdate()->find($child->unidad);
            $this->units($childUnit);
            if ($factor % (int) $childUnit->cantidad_v !== 0) {
                throw new DomainException('Las unidades del padre no completan una unidad de venta del hijo.');
            }
            $quantity = $data['cantidad'];
            if ($quantity < 1 || $quantity > 999 || $quantity > (int) $lot->cantidad || $lot->costo === null || (float) $lot->costo < 0) {
                throw new DomainException('Revisa la cantidad de padres y el costo disponible del lote.');
            }
            if ($lot->fecha_exp !== null && $lot->fecha_exp->toDateString() < now('America/Tegucigalpa')->toDateString()) {
                throw new DomainException('No se puede convertir un lote vencido.');
            }
            $produced = $quantity * $factor;
            if ($produced > 1000000) {
                throw new DomainException('La conversión supera el máximo de unidades permitido.');
            }
            // Each conversion retains its own cost and commercial snapshot, even for the same batch.
            $converted = Lote::create([
                'compra' => $lot->compra, 'producto' => $child->id, 'lote' => $lot->lote,
                'fecha_elab' => $lot->fecha_elab, 'fecha_exp' => $lot->fecha_exp,
                'cantidad' => $produced, 'costo' => round((float) $lot->costo / $factor, 4),
                'isv' => $lot->isv, 'es_bonificacion' => $lot->es_bonificacion,
            ]);
            $distribution = Distribucion::query()->where('lote', $lot->id)->lockForUpdate()->first();
            if ($distribution !== null) {
                Distribucion::create([
                    'lote' => $converted->id,
                    'precio' => round((float) $distribution->precio * (int) $childUnit->cantidad_v / $factor, 2),
                    ...$distribution->only(['isv', 'dto1', 'dto2', 'dto3', 'dto4']),
                ]);
            }
            $lot->decrement('cantidad', $quantity);
            $response = [
                'lote_padre' => (int) $lot->id, 'disponible_padre' => (int) $lot->cantidad,
                'unidades_generadas' => $produced,
                'lote_hijo' => LotResource::make($converted->load('productoDetalle.laboratorioDetalle'))->resolve(),
            ];
            $response = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            DB::table('transferencia_conversiones')->insert([
                'request_id' => $data['request_id'], 'usuario' => $userId, 'stock' => $stockId,
                'lote_padre' => $lot->id, 'producto_hijo' => $child->id, 'cantidad' => $quantity,
                'respuesta' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function units(?ProdUnidad $unit): int
    {
        if ($unit === null || $unit->cantidad_c < 1 || $unit->cantidad_v < 1 || $unit->cantidad_c % $unit->cantidad_v !== 0) {
            throw new DomainException('Configura unidades de compra y venta enteras y compatibles para padre e hijo.');
        }

        return (int) $unit->cantidad_c;
    }
}
