<?php

namespace App\Services\DesktopUpdates;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class DesktopReleasePublisher
{
    public function __construct(private DesktopUpdateStorage $storage, private DesktopArtifactRegistry $registry,
        private DesktopReleaseCatalog $catalog, private DesktopReleasePermissions $permissions) {}

    private function authorize(int $actor, string $target): void
    {
        abort_unless($actor > 0 && User::whereKey($actor)->whereHas('Estado', fn ($query) => $query->where('descripcion', 'ACTIVO'))->exists(),
            403, 'Se necesita una cuenta activa de publicación.');
        foreach ($target === 'pilot' ? ['updbtn02', 'updbtn04', 'updbtn05'] : ['updbtn02'] as $action) {
            abort_unless($this->permissions->allows($actor, $action), 403, 'El publicador no conserva los permisos necesarios.');
        }
    }

    public function publish(array $input, int $actor, bool $apply = false): array
    {
        $data = Validator::make($input, [
            'schema_version' => ['required', 'integer', 'in:1'], 'request_id' => ['required', 'uuid'],
            'metadata' => ['required', 'string', 'max:4096'], 'package' => ['required', 'string', 'max:4096'],
            'target' => ['required', 'in:draft,pilot'], 'notes' => ['required', 'string', 'min:3', 'max:10000'],
            'min_api_contract' => ['required', 'integer', 'min:1', 'max:65535'],
            'min_schema_version' => ['required', 'integer', 'min:0', 'max:65535'],
            'max_schema_version' => ['nullable', 'integer', 'gte:min_schema_version', 'max:65535'], 'required' => ['required', 'boolean'],
            'pilot_activation_ids' => ['present', 'array', 'max:500'], 'pilot_activation_ids.*' => ['required', 'uuid', 'distinct'],
            'source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();
        abort_unless(count(array_diff(array_keys($input), array_keys($data))) === 0, 422, 'Campos de publicación no permitidos.');
        $data['request_id'] = strtolower($data['request_id']);
        $data['pilot_activation_ids'] = array_map('strtolower', $data['pilot_activation_ids']);
        sort($data['pilot_activation_ids']);
        $this->authorize($actor, $data['target']);
        abort_unless(is_file($data['metadata']) && filesize($data['metadata']) <= 65536, 422, 'Metadatos no disponibles.');
        $metadata = json_decode(file_get_contents($data['metadata']), true, 32, JSON_THROW_ON_ERROR);
        $artifact = DesktopReleaseArtifact::fromArray($metadata);
        $artifact->assertPackage($data['package']);
        if ($data['target'] === 'pilot') {
            abort_unless($data['pilot_activation_ids'] !== [] && $this->catalog->eligibleDevices($artifact->product)
                ->whereIn('devices.id', $data['pilot_activation_ids'])->count() === count($data['pilot_activation_ids']), 422, 'Pilotos no elegibles.');
        } else {
            abort_unless($data['pilot_activation_ids'] === [], 422, 'El borrador no asigna pilotos automáticamente.');
        }
        $preview = ['product' => $artifact->product, 'version' => $artifact->version, 'architecture' => $artifact->architecture,
            'actor_id' => $actor, 'target' => $data['target'], 'pilot_devices' => count($data['pilot_activation_ids']), 'apply' => $apply];
        if (! $apply) {
            return $preview;
        }
        $directory = config('desktop_updates.publication_state_directory', storage_path('app/private/desktop-publications'));
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el diario privado.');
        }
        $path = $directory.'/'.$data['request_id'].'.json';
        $lock = fopen($directory.'/'.$data['request_id'].'.lock', 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('La publicación ya está en ejecución.');
        }
        try {
            $canonical = $data;
            ksort($canonical);
            $hash = hash('sha256', json_encode([$actor, $canonical, $metadata], JSON_THROW_ON_ERROR));
            if (is_file($path)) {
                abort_unless(filesize($path) <= 131072, 409);
                $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                abort_unless(hash_equals($state['request_hash'], $hash), 409, 'El UUID pertenece a otra publicación.');
            } else {
                // Refuse an already cataloged version before allocating/uploading another object.
                abort_if(DB::table('desktop_releases')->where('product', $artifact->product)->where('version', $artifact->version)
                    ->where('architecture', $artifact->architecture)->exists(), 409, 'Esta versión ya tiene una publicación.');
                $state = ['request_hash' => $hash, 'actor_id' => $actor, 'product' => $artifact->product,
                    'version' => $artifact->version, 'source_sha256' => $data['source_sha256'], 'origin' => 'local-windows-pipeline',
                    'object_id' => bin2hex(random_bytes(32)), 'pilots_request_id' => (string) Str::uuid(), 'promote_request_id' => (string) Str::uuid()];
            }
            $save = function () use (&$state, $path): void {
                $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
                try {
                    if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false
                        || ! rename($temporary, $path)) {
                        throw new RuntimeException('No se pudo guardar el diario de publicación.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            };
            $save(); // Reserve the same server-owned object id before any network request.
            $this->authorize($actor, $data['target']);
            if (! isset($state['uploaded'])) {
                $this->storage->upload($data['package'], $artifact, $state['object_id']);
                $state['uploaded'] = true;
                $save();
            }
            $this->authorize($actor, $data['target']);
            if (! isset($state['artifact_id'])) {
                $state['artifact_id'] = $this->registry->register($artifact, $state['object_id'])['artifact_id'];
                $save();
            }
            $this->authorize($actor, $data['target']);
            if (! isset($state['release'])) {
                $state['release'] = $this->catalog->create(['request_id' => $data['request_id'], 'artifact_id' => $state['artifact_id'],
                    'notes' => $data['notes'], 'min_api_contract' => (int) $data['min_api_contract'],
                    'min_schema_version' => (int) $data['min_schema_version'], 'max_schema_version' => isset($data['max_schema_version']) ? (int) $data['max_schema_version'] : null,
                    'required' => (bool) $data['required']], $actor);
                $save();
            }
            if ($data['target'] === 'pilot') {
                $this->authorize($actor, 'pilot');
                if (! isset($state['pilots'])) {
                    $state['release'] = $this->catalog->change($state['release']['id'], 'pilots', ['request_id' => $state['pilots_request_id'],
                        'expected_revision' => $state['release']['revision'], 'activation_ids' => $data['pilot_activation_ids']], $actor);
                    $state['pilots'] = true;
                    $save();
                }
                $this->authorize($actor, 'pilot');
                if (! isset($state['promoted'])) {
                    $state['release'] = $this->catalog->change($state['release']['id'], 'pilot', ['request_id' => $state['promote_request_id'],
                        'expected_revision' => $state['release']['revision']], $actor);
                    $state['promoted'] = true;
                    $save();
                }
            }
            $actual = $this->catalog->record($state['release']['id']);

            return $preview + ['release_id' => $actual->id, 'status' => $actual->status, 'revision' => (int) $actual->revision];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
