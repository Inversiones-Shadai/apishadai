<?php

namespace App\Repositories\Farma;

use App\Interfaces\Farma\PurchaseCatalogService;
use App\Models\Farma\CompraTipo;
use App\Models\Farma\TransaccionTipo;
use App\Support\TableSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseCatalogRepository implements PurchaseCatalogService
{
    public function paginate(string $catalog, int $perPage = 30, string $search = ''): LengthAwarePaginator
    {
        return TableSearch::apply($this->model($catalog)->newQuery(), $search, ['descripcion'])
            ->orderBy('descripcion')->paginate(max(1, min($perPage, 100)));
    }

    public function find(string $catalog, int $id): ?Model
    {
        return $this->model($catalog)->newQuery()->find($id);
    }

    public function create(string $catalog, string $description): Model
    {
        return $this->model($catalog)->newQuery()->create(['descripcion' => $description]);
    }

    public function update(string $catalog, int $id, string $description): ?Model
    {
        $record = $this->find($catalog, $id);
        $record?->update(['descripcion' => $description]);

        return $record?->refresh();
    }

    public function delete(string $catalog, int $id): bool
    {
        $record = $this->find($catalog, $id);

        return $record !== null && (bool) $record->delete();
    }

    private function model(string $catalog): Model
    {
        return $catalog === 'tipos-compra' ? new CompraTipo : new TransaccionTipo;
    }
}
