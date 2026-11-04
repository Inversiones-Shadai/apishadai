<?php

namespace App\Interfaces\Farma;

use App\Models\Farma\Compra;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface PurchaseService
{
    public function paginate(int $perPage = 30, string $search = ''): LengthAwarePaginator;

    /** @return array{purchases: LengthAwarePaginator, totals: array<string, float>} */
    public function report(int $perPage = 30, string $search = '', ?int $provider = null, ?int $type = null): array;

    /** @return Collection<int, Compra> */
    public function exportPage(string $search = '', ?int $provider = null, ?int $type = null, ?int $before = null): Collection;

    public function find(int $id): ?Compra;

    public function create(array $header, array $details): Compra;

    public function createDraft(array $header): Compra;

    public function syncHeader(int $id, array $header): ?Compra;

    public function syncDetail(int $purchaseId, array $detail): ?Compra;

    public function deleteDetail(int $purchaseId, int $detailId): ?Compra;

    public function finalize(int $id): ?Compra;

    public function update(int $id, array $header, array $details): ?Compra;

    public function delete(int $id): bool;

    public function document(int $id): ?string;

    public function providerImage(int $id): ?string;

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{producto_id: int, costo: ?string}>
     */
    public function latestCosts(array $productIds, ?int $excludePurchase = null): array;

    public function options(bool $compact = false): array;

    public function providers(int $perPage = 10, string $search = ''): LengthAwarePaginator;
}
