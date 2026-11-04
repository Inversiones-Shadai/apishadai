<?php

namespace App\Interfaces\Farma;

use App\Interfaces\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface FamiliaService extends RepositoryInterface
{
    public function lookupPage(string $search, int $perPage = 30): LengthAwarePaginator;

    public function administraciones(): Collection;

    public function presentaciones(): Collection;
}
