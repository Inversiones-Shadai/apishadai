<?php

namespace App\Interfaces\Farma;

use App\Models\Farma\CompraTransaccion;
use Illuminate\Pagination\LengthAwarePaginator;

interface PurchaseTransactionService
{
    public function paginate(int $perPage = 30, ?int $purchaseId = null, string $search = ''): LengthAwarePaginator;

    public function find(int $id): ?CompraTransaccion;

    public function create(array $data): CompraTransaccion;

    public function update(int $id, array $data): ?CompraTransaccion;

    public function delete(int $id): bool;

    public function document(int $id): ?string;

    public function options(): array;
}
