<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use App\Models\SyncChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OutboxSender
{
    public function __construct(private readonly HeadquartersClient $client) {}

    /** @return array{sent: int, pending: int} */
    public function send(int $batches = 100): array
    {
        return $this->sendScoped($batches, null);
    }

    /** Publish master lots only; unrelated pending events remain untouched. */
    public function sendLots(int $batches = 100): array
    {
        return $this->sendScoped($batches, 'lotes');
    }

    private function sendScoped(int $batches, ?string $entity): array
    {
        $sent = 0;
        for ($batch = 0; $batch < $batches; $batch++) {
            $token = (string) Str::uuid();
            $rows = DB::transaction(function () use ($token, $entity) {
                $candidates = DB::table('hq_outbox')->whereNull('sent_at')
                    ->when($entity !== null, fn ($query) => $query->whereIn('sync_change_id',
                        DB::table('sync_changes')->select('id')->where('entity', $entity)))
                    ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', now()))
                    ->orderBy('id')->limit(100)->lockForUpdate()->get();
                $rows = collect();
                $bytes = 0;
                foreach ($candidates as $row) {
                    $change = SyncChange::findOrFail($row->sync_change_id);
                    $payload = $this->serialize($change);
                    $size = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
                    if ($rows->isNotEmpty() && $bytes + $size > 1024 * 1024) {
                        break;
                    }
                    $row->change = $payload;
                    $bytes += $size;
                    $rows->push($row);
                }
                if ($rows->isNotEmpty()) {
                    DB::table('hq_outbox')->whereIn('id', $rows->pluck('id'))->update(['claim_token' => $token, 'locked_until' => now()->addMinute()]);
                }

                return $rows;
            }, 3);
            if ($rows->isEmpty()) {
                break;
            }
            try {
                $result = $this->client->send('POST', 'changes', ['changes' => $rows->pluck('change')->all()]);
                $expected = $rows->pluck('change.uuid')->all();
                $accepted = $result['accepted'] ?? [];
                if (! is_array($accepted) || array_diff($expected, $accepted) !== []) {
                    throw new HeadquartersException('hq-api no confirmó el lote completo.', 503);
                }
                $sent += DB::table('hq_outbox')->where('claim_token', $token)->update(['sent_at' => now(), 'locked_until' => null, 'claim_token' => null, 'last_status' => 200]);
            } catch (Throwable $exception) {
                foreach ($rows as $row) {
                    DB::table('hq_outbox')->where('id', $row->id)->where('claim_token', $token)->update([
                        'attempts' => $row->attempts + 1, 'next_attempt_at' => now()->addSeconds(min(3600, 10 * (2 ** min($row->attempts, 8)))),
                        'last_status' => $exception instanceof HeadquartersException ? $exception->getCode() : 503, 'locked_until' => null, 'claim_token' => null,
                    ]);
                }
                Log::warning('Cambios pendientes de envío a hq-api.', ['exception' => $exception::class, 'count' => $rows->count()]);
                break;
            }
        }

        return ['sent' => $sent, 'pending' => DB::table('hq_outbox')->whereNull('sent_at')
            ->when($entity !== null, fn ($query) => $query->whereIn('sync_change_id',
                DB::table('sync_changes')->select('id')->where('entity', $entity)))->count()];
    }

    public function serialize(SyncChange $change): array
    {
        return ['uuid' => $change->uuid, 'entity' => $change->entity, 'entity_id' => (string) $change->entity_id,
            'action' => $change->action, 'version' => (string) $change->version, 'schema_version' => $change->schema_version,
            'payload' => $change->payload, 'created_at' => $change->created_at->toIso8601String(),
            'stock_ids' => $change->stock_ids ?? [], 'removed_stock_ids' => $change->removed_stock_ids ?? []];
    }
}
