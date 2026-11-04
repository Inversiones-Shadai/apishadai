<?php

namespace App\Repositories\Farma;

use App\DTO\Farma\PurchaseData;
use App\Interfaces\Farma\TransferService;
use App\Models\Farma\Lote;
use App\Models\Farma\Transferencia;
use App\Models\Farma\TransferenciaEstado;
use App\Models\Farma\TransferenciaTipo;
use App\Models\Stocks;
use App\Services\Headquarters\ChangeRecorder;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransferRepository implements TransferService
{
    private const RELATIONS = [
        'tipoDetalle:id,descripcion',
        'stockOrigen:id,descripcion',
        'stockDestino:id,descripcion',
        'usuarioEnvia:id,nombre,name',
        'usuarioRecibe:id,nombre,name',
        'estadoDetalle:id,descripcion',
        'estadoRecepcionDetalle:id,descripcion',
        'detalles.loteDetalle.productoDetalle:id,codigo,descripcion',
    ];

    public function __construct(private readonly Transferencia $model) {}

    /** @param array<string, mixed> $filters */
    public function paginate(int $stockId, int $perPage = 30, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseQuery()->where(function (Builder $participant) use ($stockId): void {
            $participant->where('stock_de', $stockId)->orWhere('stock_para', $stockId);
        });

        $direction = (string) ($filters['direccion'] ?? 'todas');

        if ($direction === 'enviadas') {
            $query->where('stock_de', $stockId);
        } elseif ($direction === 'recibidas') {
            $query->where('stock_para', $stockId);
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', (int) $filters['estado']);
        }

        if (! empty($filters['estado_recepcion'])) {
            $query->where('estado_recepcion', (int) $filters['estado_recepcion']);
        }

        if (! empty($filters['tipo'])) {
            $query->where('tipo', (int) $filters['tipo']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if (! empty($filters['fecha_desde'])) {
            $query->where('enviado_at', '>=', $filters['fecha_desde'].' 00:00:00');
        }

        if (! empty($filters['fecha_hasta'])) {
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['fecha_hasta'], 'America/Tegucigalpa')->addDay();
            $query->where('enviado_at', '<', $end->toDateTimeString());
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function (Builder $searchQuery) use ($search, $like): void {
                if (ctype_digit($search)) {
                    $searchQuery->orWhereKey((int) $search);
                }

                $searchQuery
                    ->orWhereHas('stockOrigen', fn (Builder $stock): Builder => $stock->whereRaw('LOWER(descripcion) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('stockDestino', fn (Builder $stock): Builder => $stock->whereRaw('LOWER(descripcion) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('usuarioEnvia', fn (Builder $user): Builder => $user->whereRaw('LOWER(nombre) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('usuarioRecibe', fn (Builder $user): Builder => $user->whereRaw('LOWER(nombre) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('detalles.loteDetalle', fn (Builder $lot): Builder => $lot->whereRaw('LOWER(lote) LIKE ?', [mb_strtolower($like)]))
                    ->orWhereHas('detalles.loteDetalle.productoDetalle', fn (Builder $product): Builder => $product
                        ->whereRaw('LOWER(codigo) LIKE ?', [mb_strtolower($like)])
                        ->orWhereRaw('LOWER(descripcion) LIKE ?', [mb_strtolower($like)]));
            });
        }

        return $query->orderByDesc('id')->paginate(max(1, min($perPage, 100)));
    }

    public function findForStock(int $id, int $stockId): ?Transferencia
    {
        return $this->baseQuery()
            ->with([
                'stockOrigen:id,descripcion,telefono,ubicacion',
                'stockDestino:id,descripcion,telefono,ubicacion',
                'detalles.loteDetalle.compraDetalle:id,nro',
                'detalles.loteDetalle.productoDetalle:id,codigo,codigobar,descripcion,laboratorio,categoria,unidad,familia',
                'detalles.loteDetalle.productoDetalle.laboratorioDetalle:id,nombre',
                'detalles.loteDetalle.productoDetalle.categoriaDetalle:id,nombre',
                'detalles.loteDetalle.productoDetalle.unidadDetalle:id,abreviatura_c,cantidad_c,abreviatura_v,cantidad_v',
                'detalles.loteDetalle.productoDetalle.Familia:id,descripcion,presentacion,administracion',
                'detalles.loteDetalle.productoDetalle.Familia.Presentacion:id,descripcion',
                'detalles.loteDetalle.productoDetalle.Familia.Administracion:id,descripcion',
            ])
            ->whereKey($id)
            ->where(fn (Builder $participant): Builder => $participant
                ->where('stock_de', $stockId)
                ->orWhere('stock_para', $stockId))
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function send(int $originStockId, int $userId, array $data): Transferencia
    {
        return DB::transaction(function () use ($originStockId, $userId, $data): Transferencia {
            $destinationStockId = (int) $data['stock_para'];

            if ($originStockId === $destinationStockId) {
                throw new DomainException('El stock de destino debe ser diferente al stock de origen.');
            }

            $destination = Stocks::query()->with('Estado')->lockForUpdate()->find($destinationStockId);

            if ($destination === null || mb_strtoupper((string) ($destination->Estado?->descripcion ?? '')) !== 'ACTIVO') {
                throw new DomainException('El stock de destino no está activo.');
            }

            $sentStatusId = $this->statusId('ENVIADA');
            $pendingStatusId = $this->statusId('PENDIENTE');
            $details = collect($data['detalles'] ?? []);
            if ($details->isEmpty()) {
                throw new DomainException('Agrega al menos un lote a la transferencia.');
            }
            $rows = [];
            $items = 0;
            $subtotal = 0.0;
            $tax = 0.0;
            $total = 0.0;

            if ($details->isNotEmpty()) {
                $lots = Lote::query()->whereKey($details->pluck('lote')->map(fn ($id): int => (int) $id)->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($lots->count() !== $details->count()) {
                    throw new DomainException('Uno o más lotes ya no están disponibles.');
                }

                foreach ($details as $detail) {
                    $lot = $lots->get((int) $detail['lote']);
                    $quantity = filter_var($detail['cantidad'] ?? null, FILTER_VALIDATE_INT);
                    if ($quantity === false || $quantity < 1) {
                        throw new DomainException('La cantidad de cada lote debe ser un número entero mayor que cero.');
                    }

                    if ($quantity > (int) $lot->cantidad) {
                        throw new DomainException("La cantidad solicitada supera la existencia del lote {$lot->lote}.");
                    }

                    if ($lot->costo === null) {
                        throw new DomainException("El lote {$lot->lote} no tiene costo registrado.");
                    }

                    $line = PurchaseData::lineTotals([
                        'cantidad' => $quantity,
                        'costo' => (float) $lot->costo,
                        'isv' => (bool) $lot->isv,
                        'descuento' => 0,
                    ]);
                    $rows[] = [
                        'lote' => (int) $lot->id,
                        'cantidad' => $quantity,
                        'subtotal' => $line['subtotal'],
                        'isv' => $line['isv'],
                        'total' => $line['total'],
                    ];
                    $items += $quantity;
                    $subtotal += (float) $line['subtotal'];
                    $tax += (float) $line['isv'];
                    $total += (float) $line['total'];
                    $lot->decrement('cantidad', $quantity);
                }
            }

            $transfer = app(ChangeRecorder::class)->withoutRecording(fn () => $this->model->newQuery()->create([
                'tipo' => (int) $data['tipo'],
                'stock_de' => $originStockId,
                'stock_para' => $destinationStockId,
                'usuario_envia' => $userId,
                'usuario_recibe' => null,
                'items' => $items,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'isvtotal' => number_format($tax, 2, '.', ''),
                'total' => number_format($total, 2, '.', ''),
                'estado' => $sentStatusId,
                'estado_recepcion' => $pendingStatusId,
                'enviado_at' => now(),
                'recibido_at' => null,
            ]));

            if ($rows !== []) {
                app(ChangeRecorder::class)->withoutRecording(fn () => $transfer->detalles()->createMany($rows));
            }
            app(ChangeRecorder::class)->record($transfer, 'CREATE');

            return $this->findForStock((int) $transfer->id, $originStockId) ?? $transfer;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateDetails(int $id, int $originStockId, array $data): Transferencia
    {
        $transfer = $this->model->newQuery()->whereKey($id)->where('stock_de', $originStockId)->first();
        if ($transfer === null) {
            throw new DomainException('Transferencia no encontrada o no pertenece a este stock.');
        }

        throw new DomainException('No se puede modificar una transferencia enviada. Solo está disponible para consulta.');
    }

    public function receive(int $id, int $destinationStockId, int $userId): ?Transferencia
    {
        return DB::transaction(function () use ($id, $destinationStockId, $userId): ?Transferencia {
            $transfer = $this->model->newQuery()
                ->with(['estadoDetalle', 'estadoRecepcionDetalle'])
                ->lockForUpdate()
                ->find($id);

            if ($transfer === null) {
                return null;
            }

            if (Schema::hasTable('pos_transferencias_centrales')
                && DB::table('pos_transferencias_centrales')->where('legacy_id', $id)->exists()) {
                throw new DomainException('Este documento ya está en el canal POS; confirma su recepción desde ShPOS en el stock destino.');
            }
            if ((int) $transfer->stock_para !== $destinationStockId) {
                throw new DomainException('La transferencia solo puede recibirse desde el stock de destino.');
            }

            if ((int) $transfer->usuario_envia === $userId) {
                throw new DomainException('La transferencia debe ser recibida por un usuario diferente al que la envió.');
            }

            if (mb_strtoupper((string) ($transfer->estadoDetalle?->descripcion ?? '')) !== 'ENVIADA'
                || mb_strtoupper((string) ($transfer->estadoRecepcionDetalle?->descripcion ?? '')) !== 'PENDIENTE'
                || $transfer->usuario_recibe !== null) {
                throw new DomainException('La transferencia ya fue recibida o no se encuentra pendiente de recepción.');
            }

            $transfer->update([
                'usuario_recibe' => $userId,
                'estado_recepcion' => $this->statusId('RECIBIDA'),
                'recibido_at' => now(),
            ]);

            return $this->findForStock($id, $destinationStockId);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function options(int $stockId, bool $compact = false): array
    {
        if ($compact) {
            return ['stock_actual' => Stocks::query()->find($stockId, ['id', 'descripcion']),
                'stocks_destino' => [], 'tipos' => [], 'estados' => [], 'estados_envio' => [], 'estados_recepcion' => []];
        }

        return [
            'stock_actual' => Stocks::query()->find($stockId, ['id', 'descripcion']),
            'stocks_destino' => Stocks::query()
                ->whereKeyNot($stockId)
                ->whereHas('Estado', fn (Builder $state): Builder => $state->whereRaw('UPPER(descripcion) = ?', ['ACTIVO']))
                ->orderBy('descripcion')
                ->get(['id', 'descripcion']),
            'tipos' => TransferenciaTipo::query()->orderBy('descripcion')->get(['id', 'descripcion']),
            'estados' => TransferenciaEstado::query()->orderBy('id')->get(['id', 'descripcion']),
            'estados_envio' => TransferenciaEstado::query()
                ->whereRaw('UPPER(descripcion) = ?', ['ENVIADA'])
                ->get(['id', 'descripcion']),
            'estados_recepcion' => TransferenciaEstado::query()
                ->whereIn(DB::raw('UPPER(descripcion)'), ['PENDIENTE', 'RECIBIDA'])
                ->orderBy('id')
                ->get(['id', 'descripcion']),
        ];
    }

    public function paginateLots(int $perPage = 30, string $search = '', ?int $productId = null): LengthAwarePaginator
    {
        $search = trim($search);
        $query = Lote::query()
            ->where('cantidad', '>', 0)
            ->whereNotNull('costo')
            ->with(['productoDetalle:id,codigo,descripcion,laboratorio', 'productoDetalle.laboratorioDetalle:id,nombre']);

        if ($productId !== null && $productId > 0) {
            $query->where('producto', $productId);
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(fn (Builder $filter): Builder => $filter
                ->whereRaw('LOWER(lote) LIKE ?', [mb_strtolower($like)])
                ->orWhereHas('productoDetalle', fn (Builder $product): Builder => $product
                    ->whereRaw('LOWER(codigo) LIKE ?', [mb_strtolower($like)])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', [mb_strtolower($like)])));
        }

        return $query->orderBy('fecha_exp')->orderBy('lote')->paginate(max(1, min($perPage, 100)));
    }

    private function baseQuery(): Builder
    {
        return $this->model->newQuery()->with(self::RELATIONS);
    }

    private function statusId(string $description): int
    {
        $id = TransferenciaEstado::query()
            ->whereRaw('UPPER(descripcion) = ?', [mb_strtoupper($description)])
            ->value('id');

        if ($id === null) {
            throw new DomainException("No está configurado el estado de transferencia {$description}.");
        }

        return (int) $id;
    }
}
