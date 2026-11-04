<?php

namespace App\Services\DesktopUpdates;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DesktopUpdatePackage
{
    public function __construct(private readonly DesktopUpdateStorage $storage) {}

    public function reference(object $release): object
    {
        $record = DB::table('desktop_release_artifacts')->where('id', $release->artifact_id)->first();
        if (! $record) {
            throw new RuntimeException('Falta el artefacto verificado.');
        }
        $artifact = DesktopReleaseArtifact::fromArray(json_decode($record->metadata, true, 32, JSON_THROW_ON_ERROR));
        if ($record->bucket !== $this->storage->describe()['bucket'] || $record->object_key !== $this->storage->key($artifact, $record->object_id)
            || $artifact->product !== $release->product || $artifact->version !== $release->version || $artifact->architecture !== $release->architecture
            || $record->sha256 !== $artifact->sha256 || (int) $record->size_bytes !== $artifact->size
            || $record->product !== $artifact->product || $record->version !== $artifact->version || $record->architecture !== $artifact->architecture
            || $record->package_name !== $artifact->packageName || $record->publisher !== $artifact->publisher) {
            throw new RuntimeException('La referencia privada no coincide con la publicación verificada.');
        }

        return $record;
    }

    public function manifest(object $release, object $artifact): array
    {
        return ['release_id' => $release->id, 'revision' => (int) $release->revision,
            'product' => $release->product, 'version' => $release->version, 'architecture' => $release->architecture,
            'channel' => $release->status, 'sha256' => $artifact->sha256, 'size_bytes' => (int) $artifact->size_bytes,
            'package_name' => $artifact->package_name, 'publisher' => $artifact->publisher, 'notes' => $release->notes,
            'min_api_contract' => (int) $release->min_api_contract, 'min_schema_version' => (int) $release->min_schema_version,
            'max_schema_version' => $release->max_schema_version === null ? null : (int) $release->max_schema_version,
            'required' => (bool) $release->required];
    }

    public function download(object $artifact, DateTimeImmutable $notAfter): array
    {
        return $this->storage->temporaryDownload(
            DesktopReleaseArtifact::fromArray(json_decode($artifact->metadata, true, 32, JSON_THROW_ON_ERROR)),
            $artifact->object_id, $notAfter);
    }
}
