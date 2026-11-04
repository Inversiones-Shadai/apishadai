<?php

namespace App\Repositories\Farma;

use App\Interfaces\Farma\PurchaseTransactionService;
use App\Models\Farma\Compra;
use App\Models\Farma\CompraTransaccion;
use App\Models\Farma\TransaccionTipo;
use App\Support\TableSearch;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseTransactionRepository implements PurchaseTransactionService
{
    public function __construct(private readonly CompraTransaccion $model) {}

    public function paginate(int $perPage = 30, ?int $purchaseId = null, string $search = ''): LengthAwarePaginator
    {
        return TableSearch::apply($this->baseQuery(), $search, ['nro', 'valor'], [
            'compraDetalle' => ['nro'], 'compraDetalle.proveedorDetalle' => ['nombre'], 'tipoDetalle' => ['descripcion'],
        ])
            ->when($purchaseId !== null, fn (Builder $query): Builder => $query->where('compra', $purchaseId))
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, 100)));
    }

    public function find(int $id): ?CompraTransaccion
    {
        return $this->baseQuery()->find($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): CompraTransaccion
    {
        return DB::transaction(function () use ($data): CompraTransaccion {
            $this->assertPaymentAllowed((int) $data['compra'], (int) $data['tipo'], (float) $data['valor']);
            $transaction = $this->model->newQuery()->create($data);
            $this->refreshPurchaseStatus((int) $data['compra']);

            return $this->find((int) $transaction->getKey()) ?? $transaction;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): ?CompraTransaccion
    {
        return DB::transaction(function () use ($id, $data): ?CompraTransaccion {
            $transaction = $this->model->newQuery()->lockForUpdate()->find($id);

            if ($transaction === null) {
                return null;
            }

            $previousPurchase = (int) $transaction->compra;
            $this->assertPaymentAllowed((int) $data['compra'], (int) $data['tipo'], (float) $data['valor'], $id);
            $transaction->update($data);
            $this->refreshPurchaseStatus($previousPurchase);
            $this->refreshPurchaseStatus((int) $data['compra']);

            return $this->find($id);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $transaction = $this->model->newQuery()->lockForUpdate()->find($id);

            if ($transaction === null) {
                return false;
            }

            $purchaseId = (int) $transaction->compra;
            $deleted = (bool) $transaction->delete();
            $this->refreshPurchaseStatus($purchaseId);

            return $deleted;
        });
    }

    public function document(int $id): ?string
    {
        $value = $this->model->newQuery()->whereKey($id)->value('img');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function options(): array
    {
        $purchases = Compra::query()
            ->where('estado', '!=', 'borrador')
            ->with('proveedorDetalle:id,nombre')
            ->withSum('transacciones as total_aplicado', 'valor')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'proveedor', 'nro', 'total']);

        return [
            'compras' => $purchases->map(function (Compra $purchase): array {
                $balance = max(0, (float) $purchase->total - (float) ($purchase->total_aplicado ?? 0));

                return [
                    'id' => (int) $purchase->id,
                    'label' => sprintf('%s · %s · saldo L %s', $purchase->nro, $purchase->proveedorDetalle?->nombre ?? '', number_format($balance, 2)),
                    'saldo' => $balance,
                    'total' => (float) $purchase->total,
                    'total_aplicado' => (float) ($purchase->total_aplicado ?? 0),
                ];
            })->all(),
            'tipos' => TransaccionTipo::query()->orderBy('descripcion')->get(['id', 'descripcion'])->map(fn (TransaccionTipo $type): array => [
                'id' => (int) $type->id, 'label' => (string) $type->descripcion,
            ])->all(),
        ];
    }

    private function baseQuery(): Builder
    {
        return $this->model->newQuery()
            ->select(['id', 'nro', 'compra', 'tipo', 'valor', 'created_at', 'updated_at'])
            ->selectRaw('img IS NOT NULL AS tiene_documento, CASE WHEN LENGTH(img) = 36 THEN img ELSE NULL END AS img')
            ->with(['tipoDetalle', 'compraDetalle.proveedorDetalle:id,nombre']);
    }

    private function assertPaymentAllowed(int $purchaseId, int $typeId, float $value, ?int $exceptId = null): void
    {
        $purchase = Compra::query()->lockForUpdate()->find($purchaseId);

        if ($purchase === null) {
            throw new DomainException('La compra seleccionada no existe.');
        }

        if ($purchase->estado === 'borrador') {
            throw new DomainException('Finaliza la compra antes de registrar pagos.');
        }

        $applied = CompraTransaccion::query()
            ->where('compra', $purchaseId)
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $exceptId))
            ->sum('valor');

        if ($this->isCashPayment($typeId)) {
            if ((float) $applied > 0.005) {
                throw new DomainException('El pago al contado solo puede registrarse cuando la factura no tiene abonos o notas aplicadas.');
            }

            if (abs($value - (float) $purchase->total) > 0.005) {
                throw new DomainException('El pago al contado debe ser exactamente por el total de la factura: L '.number_format((float) $purchase->total, 2).'.');
            }
        }

        if ($value > ((float) $purchase->total - (float) $applied) + 0.005) {
            throw new DomainException('El valor supera el saldo pendiente de la compra.');
        }
    }

    private function isCashPayment(int $typeId): bool
    {
        $description = (string) TransaccionTipo::query()->whereKey($typeId)->value('descripcion');

        return Str::contains(Str::lower($description), 'pago al contado');
    }

    private function refreshPurchaseStatus(int $purchaseId): void
    {
        $purchase = Compra::query()->lockForUpdate()->find($purchaseId);

        if ($purchase === null || $purchase->estado === 'borrador') {
            return;
        }

        $applied = (float) CompraTransaccion::query()->where('compra', $purchaseId)->sum('valor');
        $purchase->update([
            'estado' => (float) $purchase->total > 0 && $applied >= ((float) $purchase->total - 0.005)
                ? 'pagada'
                : 'pendiente',
        ]);
    }
}
