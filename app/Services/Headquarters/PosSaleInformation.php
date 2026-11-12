<?php

namespace App\Services\Headquarters;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use stdClass;

class PosSaleInformation
{
    public static function nextRevision(int $count = 1): int
    {
        $stream = DB::table('pos_sale_information_stream')->where('id', 1);
        $first = (int) $stream->lockForUpdate()->value('sequence') + 1;
        $stream->update(['sequence' => $first + $count - 1]);

        return $first;
    }

    public function exchange(int $deviceId, string $clientUuid, array $payload): array
    {
        $data = Validator::make($payload, [
            'stock_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'balance_after' => ['required', 'integer', 'min:0'],
            'alert_after' => ['required', 'integer', 'min:0'],
            'upload' => ['nullable', 'array:request_id,rows'],
            'upload.request_id' => ['required_with:upload', 'uuid'],
            'upload.rows' => ['required_with:upload', 'array', 'list', 'min:1', 'max:500'],
            'upload.rows.*' => ['required', 'array:producto_id,cantidad'],
            'upload.rows.*.producto_id' => ['required', 'string', 'distinct:strict', 'regex:/\A[1-9][0-9]{0,19}\z/'],
            'upload.rows.*.cantidad' => ['required', 'string', 'regex:/\A[0-9]{1,15}(\.[0-9]{1,4})?\z/'],
        ])->validate();

        return DB::transaction(function () use ($deviceId, $clientUuid, $data): array {
            $device = Device::with(['Estado', 'Stock.Estado'])->lockForUpdate()->find($deviceId);
            abort_unless($device && $device->hq_client_uuid === $clientUuid
                && strtoupper((string) $device->Estado?->descripcion) === 'ACTIVO'
                && strtoupper((string) $device->Stock?->Estado?->descripcion) === 'ACTIVO', 401);
            $stock = (int) $device->stock;
            abort_unless((int) $data['stock_id'] === $stock, 403);
            $upload = $data['upload'] ?? null;
            if ($upload !== null) {
                $this->publish($stock, $upload);
            }

            $balances = DB::table('pos_branch_balances')->where('revision', '>', $data['balance_after'])
                ->orderBy('revision')->limit(501)->get();
            $alerts = DB::table('pos_sale_alerts')->where('revision', '>', $data['alert_after'])
                ->orderBy('revision')->limit(201)->get();

            return [
                'stock_id' => $stock,
                'accepted_request_id' => $upload['request_id'] ?? null,
                'more_balances' => $balances->count() > 500,
                'more_alerts' => $alerts->count() > 200,
                'balances' => $balances->take(500)->map(fn (stdClass $row): array => [
                    'stock_id' => (int) $row->stock_id,
                    'producto_id' => (string) $row->producto_id,
                    'cantidad' => (string) $row->cantidad,
                    'revision' => (string) $row->revision,
                    'actualizado' => Carbon::parse($row->updated_at)->utc()->toIso8601String(),
                ])->all(),
                'alerts' => $alerts->take(200)->map(function (stdClass $row) use ($stock): array {
                    $base = ['id' => (string) $row->id, 'revision' => (string) $row->revision];
                    if (! $row->activo || $row->retirada || ($row->stock_id !== null && (int) $row->stock_id !== $stock)) {
                        return $base + ['retirada' => true];
                    }

                    return $base + [
                        'retirada' => false, 'stock_id' => $row->stock_id === null ? null : (int) $row->stock_id,
                        'producto_id' => $row->producto_id === null ? null : (string) $row->producto_id,
                        'categoria_id' => $row->categoria_id === null ? null : (string) $row->categoria_id,
                        'titulo' => $row->titulo, 'requisitos' => json_decode($row->requisitos, true, flags: JSON_THROW_ON_ERROR),
                    ];
                })->all(),
            ];
        }, 3);
    }

    private function publish(int $stock, array $upload): void
    {
        // Serialize commits for sequence allocation and receipt replay across devices.
        DB::table('pos_sale_information_stream')->where('id', 1)->lockForUpdate()->first();
        $hash = hash('sha256', json_encode($upload['rows'], JSON_THROW_ON_ERROR));
        $receipt = DB::table('pos_branch_balance_receipts')->where('request_id', $upload['request_id'])->first();
        if ($receipt) {
            abort_unless((int) $receipt->stock_id === $stock && hash_equals($receipt->hash, $hash), 409);

            return;
        }

        $products = DB::table('productos')->whereIn('id', array_column($upload['rows'], 'producto_id'))->pluck('id')
            ->mapWithKeys(fn ($id): array => [(string) $id => true])->all();
        $rows = array_filter($upload['rows'], fn (array $row): bool => isset($products[$row['producto_id']]));
        if ($rows !== []) {
            $revision = self::nextRevision(count($rows));
            $now = now();
            $balances = [];
            foreach ($rows as $row) {
                $balances[] = [
                    'stock_id' => $stock, 'producto_id' => $row['producto_id'], 'cantidad' => $row['cantidad'],
                    'revision' => $revision++, 'updated_at' => $now,
                ];
            }
            DB::table('pos_branch_balances')->upsert($balances, ['stock_id', 'producto_id'], ['cantidad', 'revision', 'updated_at']);
        }
        DB::table('pos_branch_balance_receipts')->insert(['request_id' => $upload['request_id'], 'stock_id' => $stock, 'hash' => $hash]);
    }
}
