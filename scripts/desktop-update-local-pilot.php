<?php

// Explicit, local-only pilot publisher. Actor 0 is this technical CLI run, never a human account.
use App\Services\DesktopUpdates\DesktopArtifactRegistry;
use App\Services\DesktopUpdates\DesktopReleaseArtifact;
use App\Services\DesktopUpdates\DesktopReleaseCatalog;
use App\Services\DesktopUpdates\DesktopUpdateStorage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if (! $app->environment('local') || config('database.connections.'.config('database.default').'.host') !== '127.0.0.1'
        || count($argv) !== 2) {
        throw new RuntimeException('El piloto CLI solo admite el backend local y un manifiesto explícito.');
    }
    $input = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    if (($input['purpose'] ?? '') !== 'authorized-local-desktop-pilot-20261029' || ! in_array($input['product'] ?? '', ['shpos', 'shpos-administrator', 'hq-client'], true)) {
        throw new RuntimeException('Manifiesto de piloto no reconocido.');
    }
    $statePath = $argv[1].'.state.json';
    $state = is_file($statePath) ? json_decode(file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR) : [];
    $save = static function () use (&$state, $statePath): void {
        file_put_contents($statePath.'.tmp', json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($statePath.'.tmp', $statePath);
    };
    $metadata = json_decode(file_get_contents($input['metadata']), true, 32, JSON_THROW_ON_ERROR);
    if ($metadata['Version'] !== '1.0.2.0' || $metadata['Product'] !== $input['product']
        || $metadata['SigningCertificateThumbprint'] !== '7E62D589A644E103AC0A75676FA33A7DCCE3408E') {
        throw new RuntimeException('El paquete no corresponde al objetivo firmado del piloto.');
    }
    $artifact = DesktopReleaseArtifact::fromArray($metadata);
    $artifact->assertPackage($input['package']);
    $device = DB::table('desktop_license_devices')->where('id', $input['activation_id'])->first();
    $license = DB::table('desktop_licenses')->where('id', $device?->license_id)->first();
    if (! $device || ! $license || $license->product !== $input['product'] || ! $license->enabled || $device->revoked_at !== null
        || ! hash_equals($device->key_hash, $input['key_hash']) || strtotime($license->expires_at.' UTC') <= time()) {
        throw new RuntimeException('El equipo del piloto no conserva licencia vigente y clave coincidente.');
    }
    if (! isset($state['object_id'])) {
        $upload = app(DesktopUpdateStorage::class)->upload($input['package'], $artifact);
        $state['object_id'] = $upload['object_id'];
        $save();
    }
    if (! isset($state['artifact_id'])) {
        $registered = app(DesktopArtifactRegistry::class)->register($artifact, $state['object_id']);
        $state['artifact_id'] = $registered['artifact_id'];
        $save();
    }
    $catalog = app(DesktopReleaseCatalog::class);
    if (! isset($state['release'])) {
        $state['release'] = $catalog->create(['request_id' => $input['create_request_id'], 'artifact_id' => $state['artifact_id'],
            'notes' => 'Piloto local autorizado 29/10/2026. Solo este equipo; certificado autofirmado; no promover a estable.',
            'min_api_contract' => 1, 'min_schema_version' => $input['schema_version'], 'max_schema_version' => $input['schema_version'], 'required' => false], 0);
        $save();
    }
    if (! isset($state['assigned'])) {
        $state['release'] = $catalog->change($state['release']['id'], 'pilots', ['request_id' => $input['pilots_request_id'],
            'expected_revision' => $state['release']['revision'], 'activation_ids' => [$input['activation_id']]], 0);
        $state['assigned'] = true;
        $save();
    }
    if (! isset($state['promoted'])) {
        $state['release'] = $catalog->change($state['release']['id'], 'pilot', ['request_id' => $input['promote_request_id'],
            'expected_revision' => $state['release']['revision']], 0);
        $state['promoted'] = true;
        $save();
    }
    echo json_encode(['product' => $input['product'], 'version' => $metadata['Version'], 'status' => $state['release']['status'],
        'revision' => $state['release']['revision'], 'assigned_pilot_devices' => 1, 'technical_actor' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Piloto local rechazado ('.$error::class.'). No se registran credenciales ni enlaces.'.PHP_EOL);
    exit(1);
}
