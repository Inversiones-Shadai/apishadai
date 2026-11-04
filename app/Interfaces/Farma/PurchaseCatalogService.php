<?php

namespace App\Interfaces\Farma;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface PurchaseCatalogService
{
    public function paginate(string $catalog, int $perPage = 30, string $search = ''): LengthAwarePaginator;

    public function find(string $catalog, int $id): ?Model;

    public function create(string $catalog, string $description): Model;

    public function update(string $catalog, int $id, string $description): ?Model;

    public function delete(string $catalog, int $id): bool;
}
