<?php

namespace App\Services\DesktopUpdates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DesktopArtifactRegistry
{
    public function register(DesktopReleaseArtifact $artifact, string $objectId): array
    {
        // Network and full byte verification occur before opening any SQL transaction.
        $location = app(DesktopUpdateStorage::class)->verifyForCatalog($artifact, $objectId);
        $metadata = ['SchemaVersion' => 1, 'Product' => $artifact->product, 'Version' => $artifact->version,
            'Architecture' => $artifact->architecture, 'Sha256' => $artifact->sha256, 'SizeBytes' => $artifact->size,
            'PackageName' => $artifact->packageName, 'Publisher' => $artifact->publisher,
            'HasSignature' => true, 'DistributionEligible' => true];

        return DB::transaction(function () use ($artifact, $location, $metadata): array {
            $id = (string) Str::uuid();
            DB::table('desktop_release_artifacts')->insertOrIgnore(['id' => $id, 'product' => $artifact->product,
                'version' => $artifact->version, 'architecture' => $artifact->architecture, 'bucket' => $location['bucket'],
                'object_id' => $location['object_id'], 'object_key' => $location['key'], 'sha256' => $artifact->sha256,
                'size_bytes' => $artifact->size, 'package_name' => $artifact->packageName, 'publisher' => $artifact->publisher,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'verified_at' => now('UTC'), 'created_at' => now('UTC')]);
            $record = DB::table('desktop_release_artifacts')->where('object_id', $location['object_id'])->lockForUpdate()->first();
            abort_unless($record && $record->bucket === $location['bucket'] && $record->object_key === $location['key']
                && $record->sha256 === $artifact->sha256 && $record->product === $artifact->product
                && $record->version === $artifact->version && $record->architecture === $artifact->architecture, 409, 'El objeto ya está registrado con otros metadatos.');

            return ['artifact_id' => $record->id, 'product' => $record->product, 'version' => $record->version,
                'architecture' => $record->architecture, 'sha256' => $record->sha256];
        }, 3);
    }

    public function verify(object $record): void
    {
        $artifact = DesktopReleaseArtifact::fromArray(json_decode($record->metadata, true, 32, JSON_THROW_ON_ERROR));
        $location = app(DesktopUpdateStorage::class)->verifyForCatalog($artifact, $record->object_id);
        abort_unless($record->bucket === $location['bucket'] && $record->object_key === $location['key']
            && $record->sha256 === $artifact->sha256 && (int) $record->size_bytes === $artifact->size
            && $record->product === $artifact->product && $record->version === $artifact->version
            && $record->architecture === $artifact->architecture, 409, 'La referencia del paquete cambió.');
    }
}
