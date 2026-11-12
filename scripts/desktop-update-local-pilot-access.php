<?php

// A reversible, local-only test of permission removal after downloading the HQ pilot.
use App\Services\DesktopUpdates\DesktopReleaseCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if (! $app->environment('local') || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
        || count($argv) !== 3 || ! in_array($argv[2], ['remove', 'restore'], true)) {
        throw new RuntimeException('Solo admite el piloto local explícito.');
    }
    $pilot = config('desktop_updates.local_pilot');
    if ($pilot['purpose'] === '' || $pilot['version'] === '') {
        throw new RuntimeException('Configura el propósito y la versión del piloto local en .env.');
    }
    $input = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    $publication = json_decode(file_get_contents($argv[1].'.state.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($input['purpose'] ?? '') !== $pilot['purpose'] || ($input['product'] ?? '') !== 'hq-client'
        || ($publication['promoted'] ?? false) !== true) {
        throw new RuntimeException('No corresponde al piloto HQ autorizado.');
    }
    $id = $publication['release']['id'];
    $release = DB::table('desktop_releases')->where('id', $id)->first();
    if (! $release || $release->product !== 'hq-client' || $release->version !== $pilot['version'] || $release->status !== 'pilot') {
        throw new RuntimeException('El release ya no corresponde al piloto de prueba.');
    }
    $path = $argv[1].'.access-test.json';
    $state = is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
    $mode = $argv[2];
    if ($mode === 'remove' && isset($state['restore'])) {
        throw new RuntimeException('La prueba de retiro ya concluyó.');
    }
    if (! isset($state[$mode])) {
        if ($mode === 'restore' && ! isset($state['remove'])) {
            throw new RuntimeException('No existe un retiro registrado para restaurar.');
        }
        $assigned = DB::table('desktop_release_pilot_devices')->where('release_id', $id)->pluck('activation_id')->all();
        $expected = $mode === 'remove' ? [$input['activation_id']] : [];
        if ($assigned !== $expected) {
            throw new RuntimeException('La asignación cambió fuera de esta prueba.');
        }
        $state[$mode] = ['request_id' => (string) Str::uuid(), 'expected_revision' => (int) $release->revision,
            'activation_ids' => $mode === 'remove' ? [] : [$input['activation_id']]];
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    $result = app(DesktopReleaseCatalog::class)->change($id, 'pilots', $state[$mode], 0);
    $state["{$mode}_result"] = $result;
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['product' => 'hq-client', 'action' => $mode, 'status' => $result['status'],
        'revision' => $result['revision'], 'license_changed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Prueba de acceso local rechazada ('.$error::class.').'.PHP_EOL);
    exit(1);
}
