<?php

namespace App\Services\DesktopUpdates;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DesktopReleaseCatalog
{
    public function create(array $data, int $actor): array
    {
        return $this->operation($data['request_id'], $actor, ['create', $data], function () use ($data, $actor): object {
            $artifact = DB::table('desktop_release_artifacts')->where('id', $data['artifact_id'])->first();
            abort_unless($artifact, 422, 'Selecciona un paquete verificado.');
            $this->lockTrack($artifact->product, $artifact->architecture);
            abort_if(DB::table('desktop_releases')->where('product', $artifact->product)->where('version', $artifact->version)
                ->where('architecture', $artifact->architecture)->exists(), 409, 'Esta versión ya está registrada.');
            $id = (string) Str::uuid();
            DB::table('desktop_releases')->insert(['id' => $id, 'artifact_id' => $artifact->id, 'product' => $artifact->product,
                'version' => $artifact->version, 'version_sort' => $this->versionSort($artifact->version), 'architecture' => $artifact->architecture,
                'status' => 'draft', 'revision' => 1, ...$this->fields($data), 'created_by' => $actor,
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $this->record($id);
        });
    }

    public function change(string $id, string $action, array $data, int $actor): array
    {
        abort_unless(in_array($action, ['edit', 'pilots', 'pilot', 'stable', 'withdraw'], true), 422);
        if (in_array($action, ['pilot', 'stable'], true)
            && ! DB::table('desktop_release_operations')->where('request_id', $data['request_id'])->exists()) {
            $release = $this->record($id);
            $artifact = DB::table('desktop_release_artifacts')->where('id', $release->artifact_id)->first();
            try {
                app(DesktopArtifactRegistry::class)->verify($artifact);
            } catch (\Throwable $error) {
                if ($error instanceof HttpExceptionInterface) {
                    throw $error;
                }
                abort(503, 'No se pudo verificar el paquete privado. Reintenta sin cambiar la solicitud.');
            }
        }

        return $this->operation($data['request_id'], $actor, [$action, $id, $data], function () use ($id, $action, $data): object {
            $initial = $this->record($id);
            $track = $this->lockTrack($initial->product, $initial->architecture);
            $release = $this->record($id, true);
            abort_unless((int) $release->revision === $data['expected_revision'], 409, 'La versión cambió. Actualiza el listado antes de continuar.');
            $changes = ['revision' => $release->revision + 1, 'updated_at' => now('UTC')];
            if ($action === 'edit') {
                abort_unless($release->status === 'draft', 409, 'Solo se puede editar un borrador.');
                $changes += $this->fields($data);
            } elseif ($action === 'pilots') {
                abort_unless(in_array($release->status, ['draft', 'pilot'], true), 409, 'Esta versión ya no admite cambios de pilotos.');
                $ids = $data['activation_ids'];
                $this->assertEligiblePilots($release->product, $ids);
                DB::table('desktop_release_pilot_devices')->where('release_id', $id)->delete();
                foreach ($ids as $activation) {
                    DB::table('desktop_release_pilot_devices')->insert(['release_id' => $id, 'activation_id' => $activation]);
                }
            } elseif (in_array($action, ['pilot', 'stable'], true)) {
                abort_unless($release->status === ($action === 'pilot' ? 'draft' : 'pilot'), 409, 'La promoción debe seguir borrador → piloto → estable.');
                $ids = DB::table('desktop_release_pilot_devices')->where('release_id', $id)->pluck('activation_id')->all();
                abort_unless($ids !== [], 422, 'Asigna al menos un equipo piloto con licencia válida.');
                $this->assertEligiblePilots($release->product, $ids);
                $highest = $action === 'pilot' ? $track->highest_pilot_version : $track->highest_stable_version;
                // A pilot must also be newer than the stable head, even after retiring it.
                abort_if(($highest !== null && strcmp($release->version_sort, $highest) <= 0)
                    || ($action === 'pilot' && $track->highest_stable_version !== null && strcmp($release->version_sort, $track->highest_stable_version) <= 0),
                    409, 'Publica una versión superior a las ya promovidas.');
                if ($action === 'stable' && $track->pilot_release_id !== $id) {
                    abort(409, 'Solo el piloto vigente puede promoverse a estable.');
                }
                $changes['status'] = $action;
                $trackChanges = [$action.'_release_id' => $id, 'highest_'.$action.'_version' => $release->version_sort];
                if ($action === 'stable') {
                    $trackChanges['pilot_release_id'] = null;
                }
                DB::table('desktop_release_tracks')->where('product', $release->product)->where('architecture', $release->architecture)->update($trackChanges);
            } else {
                abort_unless($release->status !== 'withdrawn', 409, 'Esta versión ya está retirada.');
                $changes['status'] = 'withdrawn';
                $trackChanges = [];
                foreach (['pilot', 'stable'] as $channel) {
                    if ($track->{$channel.'_release_id'} === $id) {
                        $trackChanges[$channel.'_release_id'] = null;
                    }
                }
                if ($trackChanges !== []) {
                    DB::table('desktop_release_tracks')->where('product', $release->product)->where('architecture', $release->architecture)->update($trackChanges);
                }
            }
            DB::table('desktop_releases')->where('id', $id)->update($changes);

            return $this->record($id);
        });
    }

    private function operation(string $requestId, int $actor, array $payload, callable $mutate): array
    {
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($requestId, $actor, $hash, $payload, $mutate): array {
            DB::table('desktop_release_operations')->insertOrIgnore(['request_id' => $requestId, 'actor_id' => $actor,
                'request_hash' => $hash, 'created_at' => now('UTC')]);
            $operation = DB::table('desktop_release_operations')->where('request_id', $requestId)->lockForUpdate()->first();
            abort_unless($operation && (int) $operation->actor_id === $actor && hash_equals($operation->request_hash, $hash), 409, 'Este UUID ya pertenece a otra solicitud.');
            if ($operation->response !== null) {
                return json_decode($operation->response, true, 32, JSON_THROW_ON_ERROR);
            }
            $release = $mutate();
            $snapshot = (array) $release;
            $snapshot['pilot_activation_ids'] = DB::table('desktop_release_pilot_devices')->where('release_id', $release->id)->orderBy('activation_id')->pluck('activation_id')->all();
            $snapshot['reason'] = $payload[2]['reason'] ?? null;
            DB::table('desktop_release_events')->insert(['release_id' => $release->id, 'request_id' => $requestId,
                'actor_id' => $actor, 'action' => $payload[0], 'revision' => $release->revision,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            $response = ['id' => $release->id, 'revision' => (int) $release->revision, 'status' => $release->status];
            DB::table('desktop_release_operations')->where('request_id', $requestId)->update(['response' => json_encode($response, JSON_THROW_ON_ERROR)]);

            return $response;
        }, 3);
    }

    public function eligibleDevices(string $product): Builder
    {
        return DB::table('desktop_license_devices as devices')->join('desktop_licenses as licenses', 'devices.license_id', '=', 'licenses.id')
            ->where('licenses.product', $product)->where('licenses.enabled', true)->where('licenses.expires_at', '>', now('UTC'))
            ->whereNull('devices.revoked_at');
    }

    private function assertEligiblePilots(string $product, array $ids): void
    {
        $eligible = $this->eligibleDevices($product)->whereIn('devices.id', $ids)->orderBy('devices.id')->lockForUpdate()->pluck('devices.id')->all();
        abort_unless(count($eligible) === count($ids), 422,
            'Hay equipos sin licencia vigente para esta aplicación o con activación revocada.');
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['notes', 'min_api_contract', 'min_schema_version', 'max_schema_version', 'required']));
    }

    public function record(string $id, bool $lock = false): object
    {
        $record = DB::table('desktop_releases')->where('id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($record, 404, 'Versión no encontrada.');

        return $record;
    }

    private function lockTrack(string $product, string $architecture): object
    {
        $track = DB::table('desktop_release_tracks')->where('product', $product)->where('architecture', $architecture)->lockForUpdate()->first();
        abort_unless($track, 409, 'Falta preparar el canal de publicación.');

        return $track;
    }

    public function versionSort(string $version): string
    {
        return implode('.', array_map(fn ($part) => str_pad($part, 5, '0', STR_PAD_LEFT), explode('.', $version)));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
