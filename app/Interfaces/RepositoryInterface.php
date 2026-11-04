<?php

namespace App\Interfaces;

use App\Models\Utils\Filter\FilterModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface RepositoryInterface
{
    public function getAll(): Collection;

    public function paginate(): LengthAwarePaginator;

    /** @param list<string> $columns @param array<string, list<string>> $relations */
    public function searchPage(string $search, array $columns, array $relations = []): LengthAwarePaginator;

    public function findById(int $id): ?Model;

    public function findOrFail(int $id): Model;

    public function whereList(array $conditions, bool $usePagination = false, string $logicalOperator = 'AND'): Collection|LengthAwarePaginator;

    public function whereFirst(array $conditions, string $logicalOperator = 'AND'): ?Model;

    public function filterAll(FilterModel $filterModel, bool $usePagination = false): Collection|LengthAwarePaginator;

    public function filterOne(FilterModel $filterModel): ?Model;

    public function joinWhereList(array $conditions, array $tables = [], array $selects = [], bool $usePagination = false): Collection|LengthAwarePaginator;

    public function joinWhereFirst(array $conditions, array $tables = [], array $selects = []): ?Model;

    public function create(array $data): bool;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function exists(array $conditions): bool;
}
