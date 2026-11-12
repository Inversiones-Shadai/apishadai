<?php

namespace App\Interfaces\Farma;

use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface DistributionService
{
    public function paginateProducts(int $perPage = 30, ?string $search = null): LengthAwarePaginator;

    public function productLots(int $productId): Collection;

    public function saveForLot(int $lotId, array $data): ?Distribucion;

    public function createCatalogLot(array $data): Lote;
}
