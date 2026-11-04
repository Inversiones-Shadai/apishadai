<?php

namespace App\Repositories\Farma;

use App\Interfaces\Farma\DistributionService;
use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use App\Support\TableSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DistributionRepository implements DistributionService
{
    public function __construct(
        private readonly Lote $lot,
        private readonly Distribucion $distribution,
    ) {}

    public function paginateProducts(int $perPage = 30, ?string $search = null): LengthAwarePaginator
    {
        $search = trim((string) $search);
        $query = $this->lot->newQuery()
            ->leftJoin('distribucion', 'distribucion.lote', '=', 'lotes.id')
            ->select('lotes.producto')
            ->selectRaw('COUNT(lotes.id) AS lotes_count')
            ->selectRaw('COUNT(distribucion.id) AS lotes_configurados')
            ->selectRaw('SUM(lotes.cantidad) AS cantidad_total')
            ->selectRaw('MIN(lotes.fecha_exp) AS proxima_expiracion')
            ->with([
                'productoDetalle' => static fn ($product) => $product
                    ->select(['id', 'codigo', 'descripcion', 'laboratorio', 'familia'])
                    ->selectRaw('imagen IS NOT NULL AND imagen <> \'\' AS tiene_imagen, CASE WHEN LENGTH(imagen) = 36 THEN imagen ELSE NULL END AS imagen_uuid'),
                'productoDetalle.laboratorioDetalle:id,nombre',
                'productoDetalle.Familia:id,presentacion,administracion',
                'productoDetalle.Familia.Presentacion:id,descripcion',
                'productoDetalle.Familia.Administracion:id,descripcion',
            ])
            ->groupBy('lotes.producto');

        if ($search !== '') {
            $query->whereHas('productoDetalle', static function (Builder $product) use ($search): void {
                TableSearch::apply($product, $search, ['codigo', 'descripcion'], ['laboratorioDetalle' => ['nombre']]);
            });
        }

        return $query
            ->orderByRaw('CASE WHEN COUNT(distribucion.id) < COUNT(lotes.id) THEN 0 ELSE 1 END')
            ->orderByRaw('MAX(lotes.created_at) DESC')
            ->orderByRaw('MAX(lotes.id) DESC')
            ->paginate(max(1, min($perPage, 100)));
    }

    /** @return Collection<int, Lote> */
    public function productLots(int $productId): Collection
    {
        return $this->lot->newQuery()
            ->where('producto', $productId)
            ->with(['distribucionDetalle', 'compraDetalle:id,nro'])
            ->withExists('distribucionDetalle')
            ->orderBy('distribucion_detalle_exists')
            ->orderBy('fecha_exp')
            ->orderBy('lote')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function saveForLot(int $lotId, array $data): ?Distribucion
    {
        return DB::transaction(function () use ($lotId, $data): ?Distribucion {
            $lot = $this->lot->newQuery()->lockForUpdate()->find($lotId);

            if ($lot === null) {
                return null;
            }

            $existing = $this->distribution->newQuery()->where('lote', $lot->id)->first();
            $allStocks = (bool) ($data['promocion_todos_stocks'] ?? $existing?->promocion_todos_stocks ?? true);
            $stockIds = array_key_exists('promocion_stock_ids', $data) ? $data['promocion_stock_ids'] : $existing?->promocion_stock_ids;
            if (! $allStocks && empty($stockIds)) {
                throw ValidationException::withMessages(['promocion_stock_ids' => 'Selecciona al menos un stock para la promoción.']);
            }
            $data['promocion_todos_stocks'] = $allStocks;
            $data['promocion_stock_ids'] = $allStocks ? null : array_values(array_map('intval', $stockIds));
            $data['isv'] = $lot->isv !== null
                ? (bool) $lot->isv
                : (bool) ($existing?->isv ?? false);

            $distribution = $this->distribution->newQuery()->updateOrCreate(
                ['lote' => $lot->id],
                $data,
            );

            return $distribution->fresh('loteDetalle.compraDetalle');
        });
    }
}
