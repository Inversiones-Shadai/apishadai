<?php

namespace App\Interfaces\Farma;

use App\Models\Farma\Transferencia;
use Illuminate\Pagination\LengthAwarePaginator;

interface TransferService
{
    public function paginate(int $stockId, int $perPage = 30, array $filters = []): LengthAwarePaginator;
    public function findForStock(int $id, int $stockId): ?Transferencia;
    public function send(int $originStockId, int $userId, array $data): Transferencia;
    public function updateDetails(int $id, int $originStockId, array $data): Transferencia;
    public function receive(int $id, int $destinationStockId, int $userId): ?Transferencia;
    public function options(int $stockId, bool $compact = false): array;
    public function paginateLots(int $perPage = 30, string $search = '', ?int $productId = null): LengthAwarePaginator;
}
