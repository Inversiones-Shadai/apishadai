<?php

namespace App\Repositories\Farma;

use App\DTO\Farma\PurchaseData;
use App\Interfaces\Farma\PurchaseService;
use App\Models\Farma\Compra;
use App\Models\Farma\CompraDetalle;
use App\Models\Farma\CompraTipo;
use App\Models\Farma\Producto;
use App\Models\Proveedores;
use App\Services\Images\ImageStorage;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseRepository implements PurchaseService
{
    private const RELATIONS = [
        'tipoDetalle',
        'proveedorDetalle:id,nombre,imagen',
        'usuarioDetalle:id,nombre,name',
        'kardexUsuarioDetalle:id,nombre,name',
        'detalles.productoDetalle.unidadDetalle',
        'detalles.productoDetalle.Familia.Presentacion',
        'detalles.productoDetalle.laboratorioDetalle',
        'transacciones:id,compra,valor',
    ];

    public function __construct(private readonly Compra $model) {}

    public function paginate(int $perPage = 30, string $search = ''): LengthAwarePaginator
    {
        return $this->filteredQuery($search)->orderByDesc('id')->paginate(max(1, min($perPage, 100)));
    }

    public function report(int $perPage = 30, string $search = '', ?int $provider = null, ?int $type = null): array
    {
        $query = $this->filteredQuery($search)
            ->when($provider !== null, fn (Builder $query) => $query->where('compras.proveedor', $provider))
            ->when($type !== null, fn (Builder $query) => $query->where('compras.tipo', $type));
        $ids = (clone $query)->select('compras.id')->toBase();
        $payments = DB::table('compra_transc')->select('compra')
            ->selectRaw('SUM(valor) AS aplicado')->whereIn('compra', $ids)->groupBy('compra');
        $totals = (array) DB::table('compras')
            ->leftJoinSub($payments, 'pagos', 'pagos.compra', '=', 'compras.id')
            ->whereIn('compras.id', $ids)
            ->selectRaw('COALESCE(SUM(subtotal), 0) AS subtotal, COALESCE(SUM(isv), 0) AS isv, COALESCE(SUM(descuento), 0) AS descuento, COALESCE(SUM(total), 0) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN total > COALESCE(pagos.aplicado, 0) THEN total - COALESCE(pagos.aplicado, 0) ELSE 0 END), 0) AS saldo_pendiente')
            ->first();
        $totals = array_map(static fn ($value): float => round((float) $value, 2), $totals);
        $totals['gran_total'] = $totals['total'];
        $totals['abonos'] = round((float) DB::table('compra_transc')->where('tipo', 2)->whereIn('compra', $ids)->sum('valor'), 2);

        // The report needs invoice headers only, never product details or scanned documents.
        $query->setEagerLoads([])->with(['tipoDetalle', 'proveedorDetalle:id,nombre'])
            ->withSum(['transacciones as total_abonos' => fn (Builder $transactions) => $transactions->where('tipo', 2)], 'valor');

        return ['purchases' => $query->orderByDesc('id')->paginate(max(1, min($perPage, 100))), 'totals' => $totals];
    }

    public function exportPage(string $search = '', ?int $provider = null, ?int $type = null, ?int $before = null): Collection
    {
        return $this->filteredQuery($search)
            ->when($provider !== null, fn (Builder $query) => $query->where('compras.proveedor', $provider))
            ->when($type !== null, fn (Builder $query) => $query->where('compras.tipo', $type))
            ->when($before !== null, fn (Builder $query) => $query->where('compras.id', '<', $before))
            ->setEagerLoads([])->with([
                'tipoDetalle', 'proveedorDetalle:id,nombre',
                'transacciones' => fn ($query) => $query->select(['id', 'compra', 'tipo', 'nro', 'valor', 'created_at'])
                    ->with('tipoDetalle:id,descripcion')->orderBy('id'),
            ])->orderByDesc('compras.id')->limit(100)->get();
    }

    private function filteredQuery(string $search): Builder
    {
        $query = $this->baseQuery();
        $search = trim($search);

        if ($search !== '') {
            $like = "%{$search}%";

            $query->where(function (Builder $filter) use ($search, $like): void {
                $filter->whereRaw('LOWER(compras.nro) LIKE ?', [mb_strtolower($like)])
                    ->orWhereRaw('LOWER(compras.estado) LIKE ?', [mb_strtolower($like)])
                    ->orWhereRaw('LOWER(compras.nota) LIKE ?', [mb_strtolower($like)])
                    ->orWhereHas('proveedorDetalle', fn (Builder $provider): Builder => $provider->whereRaw('LOWER(nombre) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('tipoDetalle', fn (Builder $type): Builder => $type->whereRaw('LOWER(descripcion) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('usuarioDetalle', fn (Builder $user): Builder => $user
                        ->whereRaw('LOWER(nombre) LIKE ?', [mb_strtolower($like)])
                        ->orWhereRaw('LOWER(name) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('detalles', fn (Builder $detail): Builder => $detail->whereRaw('LOWER(lote) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('detalles.productoDetalle', fn (Builder $product): Builder => $product->whereRaw('LOWER(descripcion) LIKE ?', [mb_strtolower($like)]));

                if (is_numeric($search)) {
                    $amount = (float) $search;
                    $filter->orWhere('compras.subtotal', $amount)
                        ->orWhere('compras.isv', $amount)
                        ->orWhere('compras.descuento', $amount)
                        ->orWhere('compras.total', $amount)
                        ->orWhereRaw(
                            '(compras.total - COALESCE((SELECT SUM(compra_transc.valor) FROM compra_transc WHERE compra_transc.compra = compras.id), 0)) = ?',
                            [$amount],
                        );
                }
            });
        }

        return $query;
    }

    public function find(int $id): ?Compra
    {
        return $this->baseQuery()->find($id);
    }

    /** @param array<string, mixed> $header @param array<int, array<string, mixed>> $details */
    public function create(array $header, array $details): Compra
    {
        return DB::transaction(function () use ($header, $details): Compra {
            $purchase = $this->model->newQuery()->create($header);
            $purchase->detalles()->createMany($details);

            return $this->find((int) $purchase->getKey()) ?? $purchase;
        });
    }

    /** @param array<string, mixed> $header */
    public function createDraft(array $header): Compra
    {
        return $this->create([
            ...$header,
            'items' => 0,
            'isv' => 0,
            'subtotal' => 0,
            'descuento' => 0,
            'total' => 0,
            'estado' => 'borrador',
        ], []);
    }

    /** @param array<string, mixed> $header */
    public function syncHeader(int $id, array $header): ?Compra
    {
        return DB::transaction(function () use ($id, $header): ?Compra {
            $purchase = $this->editablePurchase($id);

            if ($purchase === null) {
                return null;
            }

            $purchase->update($header);

            return $this->find($id);
        });
    }

    /** @param array<string, mixed> $detail */
    public function syncDetail(int $purchaseId, array $detail): ?Compra
    {
        return DB::transaction(function () use ($purchaseId, $detail): ?Compra {
            $purchase = $this->editablePurchase($purchaseId);

            if ($purchase === null) {
                return null;
            }

            $detailId = (int) ($detail['id'] ?? 0);
            unset($detail['id']);
            $line = PurchaseData::lineTotals($detail);
            $detail['total'] = $line['total'];

            if ($detailId > 0) {
                $record = $purchase->detalles()->whereKey($detailId)->lockForUpdate()->first();

                if ($record === null) {
                    throw new DomainException('El producto no pertenece a esta compra.');
                }

                $record->update($detail);
            } else {
                $purchase->detalles()->create($detail);
            }

            $this->refreshTotals($purchase);

            return $this->find($purchaseId);
        });
    }

    public function deleteDetail(int $purchaseId, int $detailId): ?Compra
    {
        return DB::transaction(function () use ($purchaseId, $detailId): ?Compra {
            $purchase = $this->editablePurchase($purchaseId);

            if ($purchase === null) {
                return null;
            }

            $detail = $purchase->detalles()->whereKey($detailId)->lockForUpdate()->first();

            if ($detail === null) {
                throw new DomainException('El producto no pertenece a esta compra.');
            }

            $detail->delete();
            $this->refreshTotals($purchase);

            return $this->find($purchaseId);
        });
    }

    public function finalize(int $id): ?Compra
    {
        return DB::transaction(function () use ($id): ?Compra {
            $purchase = $this->model->newQuery()->with('detalles')->lockForUpdate()->find($id);

            if ($purchase === null) {
                return null;
            }

            if ($purchase->detalles->isEmpty()) {
                throw new DomainException('Agrega al menos un producto antes de finalizar la compra.');
            }

            foreach ($purchase->detalles as $detail) {
                if ((float) $detail->costo < 0 || ((float) $detail->costo == 0 && ! $detail->es_bonificacion) || trim((string) $detail->lote) === ''
                    || $detail->fecha_elaboracion === null || $detail->fecha_expiracion === null) {
                    throw new DomainException('Todos los productos deben tener lote, fechas y costo válido. Sólo las bonificaciones pueden tener costo cero.');
                }

                if ($detail->fecha_expiracion->lt($detail->fecha_elaboracion)) {
                    throw new DomainException('La fecha de expiración no puede ser anterior a la fecha de elaboración.');
                }

                $line = PurchaseData::lineTotals($detail->toArray());

                if ((float) $line['descuento'] > ((float) $line['subtotal'] + (float) $line['isv'])) {
                    throw new DomainException('El descuento de un producto no puede superar su subtotal más ISV.');
                }
            }

            $this->refreshTotals($purchase);
            $purchase->update(['estado' => 'pendiente']);

            return $this->find($id);
        });
    }

    /** @param array<string, mixed> $header @param array<int, array<string, mixed>> $details */
    public function update(int $id, array $header, array $details): ?Compra
    {
        return DB::transaction(function () use ($id, $header, $details): ?Compra {
            $purchase = $this->model->newQuery()->lockForUpdate()->find($id);

            if ($purchase === null) {
                return null;
            }

            if ($purchase->kardex_enviado_at !== null) {
                throw new DomainException('Una compra enviada al Kardex no puede modificarse.');
            }

            $purchase->update($header);
            $purchase->detalles()->delete();
            $purchase->detalles()->createMany($details);

            return $this->find($id);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $purchase = $this->model->newQuery()->lockForUpdate()->find($id);

            if ($purchase === null || $purchase->kardex_enviado_at !== null || $purchase->transacciones()->exists()) {
                return false;
            }

            $purchase->detalles()->delete();

            return (bool) $purchase->delete();
        });
    }

    public function document(int $id): ?string
    {
        $value = $this->model->newQuery()->whereKey($id)->value('img');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function providerImage(int $id): ?string
    {
        $value = Proveedores::query()->whereKey($id)->value('imagen');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{producto_id: int, costo: ?string}>
     */
    public function latestCosts(array $productIds, ?int $excludePurchase = null): array
    {
        if ($productIds === []) {
            return [];
        }

        // Each product is a bound constant so MySQL can read the covering
        // index backwards without filesort. Correlating a product table here
        // makes MySQL sort even with the same index. UNION ALL batches the
        // bounded scalar lookups into one round trip, without deduplication.
        $query = null;
        foreach (array_unique($productIds) as $id) {
            $cost = DB::table('compra_detalle as detail')
                ->select('detail.costo')
                ->where('detail.producto', $id)
                ->where('detail.es_bonificacion', false)
                ->where('detail.costo', '>', 0)
                ->when($excludePurchase !== null, fn ($detail) => $detail->where('detail.compra', '<>', $excludePurchase))
                ->where(fn ($purchase) => $purchase->select('estado')->from('compras')
                    ->whereColumn('compras.id', 'detail.compra'), '<>', 'borrador')
                ->orderByDesc('detail.compra')
                ->orderByDesc('detail.id')
                ->limit(1);
            $product = DB::query()->selectRaw('? AS producto_id', [$id])->selectSub($cost, 'costo');
            $query = $query === null ? $product : $query->unionAll($product);
        }

        return $query
            ->get()
            ->map(static fn ($product): array => [
                'producto_id' => (int) $product->producto_id,
                'costo' => $product->costo === null ? null : number_format((float) $product->costo, 2, '.', ''),
            ])->all();
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function options(bool $compact = false): array
    {
        $types = [
            'tipos' => CompraTipo::query()->orderBy('descripcion')->get()->map(fn (CompraTipo $type): array => [
                'id' => (int) $type->getKey(), 'label' => (string) $type->descripcion,
            ])->all(),
        ];

        if ($compact) {
            return $types;
        }

        return [
            ...$types,
            'proveedores' => Proveedores::query()
                ->select(['id', 'nombre'])
                ->selectRaw('imagen IS NOT NULL AND imagen <> \'\' AS tiene_imagen, CASE WHEN LENGTH(imagen) = 36 THEN imagen ELSE NULL END AS imagen_uuid')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Proveedores $provider): array => [
                    'id' => (int) $provider->id,
                    'label' => (string) $provider->nombre,
                    'tiene_imagen' => (bool) $provider->tiene_imagen,
                    'imagen_url' => app(ImageStorage::class)->url($provider->imagen_uuid ?? $provider->imagen),
                ])->all(),
            'productos' => Producto::query()
                ->with(['unidadDetalle', 'Familia.Presentacion', 'laboratorioDetalle'])
                ->orderBy('descripcion')
                ->get(['id', 'laboratorio', 'unidad', 'familia', 'descripcion'])
                ->map(fn (Producto $product): array => [
                    'id' => (int) $product->id,
                    'label' => (string) $product->descripcion,
                    'descripcion' => (string) $product->descripcion,
                    'laboratorio' => (string) ($product->laboratorioDetalle?->nombre ?? ''),
                    'presentacion' => (string) ($product->Familia?->Presentacion?->descripcion ?? ''),
                    'unidad_compra' => (int) ($product->unidadDetalle?->cantidad_c ?? 0),
                ])->all(),
        ];
    }

    public function providers(int $perPage = 10, string $search = ''): LengthAwarePaginator
    {
        $query = Proveedores::query()
            ->select(['id', 'nombre'])
            ->selectRaw('imagen IS NOT NULL AND imagen <> \'\' AS tiene_imagen, CASE WHEN LENGTH(imagen) = 36 THEN imagen ELSE NULL END AS imagen_uuid');

        if (trim($search) !== '') {
            $query->where('nombre', 'like', '%'.trim($search).'%');
        }

        return $query->orderBy('nombre')->paginate(max(1, min($perPage, 100)));
    }

    private function baseQuery(): Builder
    {
        return $this->model->newQuery()
            ->select(['id', 'tipo', 'proveedor', 'usuario', 'plazo', 'nro', 'items', 'isv', 'subtotal', 'descuento', 'total', 'estado', 'nota', 'kardex_enviado_at', 'kardex_usuario', 'created_at', 'updated_at'])
            ->selectRaw('img IS NOT NULL AS tiene_documento, CASE WHEN LENGTH(img) = 36 THEN img ELSE NULL END AS img')
            ->with(self::RELATIONS)
            ->withSum('transacciones as total_aplicado', 'valor');
    }

    private function editablePurchase(int $id): ?Compra
    {
        $purchase = $this->model->newQuery()->lockForUpdate()->find($id);

        if ($purchase?->estado === 'pagada') {
            throw new DomainException('Una compra pagada no puede modificarse.');
        }

        if ($purchase?->kardex_enviado_at !== null) {
            throw new DomainException('Una compra enviada al Kardex no puede modificarse.');
        }

        return $purchase;
    }

    private function refreshTotals(Compra $purchase): void
    {
        $totals = ['items' => 0, 'subtotal' => 0.0, 'isv' => 0.0, 'descuento' => 0.0, 'total' => 0.0];

        $purchase->detalles()->get()->each(function (CompraDetalle $detail) use (&$totals): void {
            $line = PurchaseData::lineTotals($detail->toArray());
            $totals['items'] += (int) $detail->cantidad;
            $totals['subtotal'] += (float) $line['subtotal'];
            $totals['isv'] += (float) $line['isv'];
            $totals['descuento'] += (float) $line['descuento'];
            $totals['total'] += (float) $line['total'];
        });

        $purchase->update([
            'items' => $totals['items'],
            'subtotal' => round($totals['subtotal'], 2),
            'isv' => round($totals['isv'], 2),
            'descuento' => round($totals['descuento'], 2),
            'total' => round($totals['total'], 2),
        ]);
    }
}
