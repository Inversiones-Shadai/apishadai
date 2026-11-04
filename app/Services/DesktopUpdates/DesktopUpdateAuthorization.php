<?php

namespace App\Services\DesktopUpdates;

use App\Services\Licensing\LicenseAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class DesktopUpdateAuthorization
{
    public function __construct(private readonly LicenseAuthority $authority, private readonly DesktopUpdatePackage $packages,
        private readonly DesktopReleaseCatalog $catalog) {}

    public function exchange(array $proof): array
    {
        abort_unless(config('desktop_updates.api_enabled') === true && ($proof['action'] === 'updates.report' || config('desktop_updates.enabled') === true), 503,
            'La API de actualizaciones no está habilitada.');
        $this->authority->sign(['probe' => true]);
        $rateKey = 'desktop-updates:'.$proof['key_hash'];
        abort_if(RateLimiter::tooManyAttempts($rateKey, config('desktop_updates.requests_per_minute')), 429, 'Demasiadas consultas de actualización.');
        RateLimiter::hit($rateKey, 60);
        $this->consumeNonce($proof['nonce']);
        if ($proof['action'] === 'updates.report') {
            return app(DesktopUpdateInstallationReport::class)->record($proof, $this->authority);
        }

        $initial = $this->decide($proof, false);
        $prepared = null;
        $unavailable = false;
        if ($initial['status'] === 'available') {
            try {
                $artifact = $this->packages->reference($initial['release']);
                $prepared = ['artifact' => $artifact, 'manifest' => $this->packages->manifest($initial['release'], $artifact), 'download' => null];
                if ($proof['action'] === 'updates.download') {
                    $prepared['download'] = $this->packages->download($artifact, CarbonImmutable::parse($initial['license']->expires_at, 'UTC'));
                }
            } catch (Throwable) {
                // Never retain SDK exceptions or prepared URLs in diagnostics.
                $prepared = null;
                $unavailable = true;
            }
        }

        // Cellar reads and presigning above must never run while SQL rows are locked.
        return DB::transaction(function () use ($proof, $prepared, $unavailable): array {
            $decision = $this->decide($proof, true);
            $status = $decision['status'];
            $reason = $decision['reason'];
            $manifest = null;
            $download = null;
            $issuedAt = now('UTC')->timestamp;
            $deadline = $issuedAt;
            if ($status !== 'denied') {
                $deadline = min($issuedAt + config('desktop_updates.authorization_seconds'), CarbonImmutable::parse($decision['license']->expires_at, 'UTC')->timestamp);
            }
            if ($status === 'available') {
                if ($unavailable) {
                    $status = 'unavailable';
                    $reason = 'package_unavailable';
                } elseif ($prepared === null) {
                    $status = 'retry_required';
                    $reason = 'catalog_changed';
                } else {
                    try {
                        $artifact = $this->packages->reference($decision['release']);
                        $current = $this->packages->manifest($decision['release'], $artifact);
                        $same = $current === $prepared['manifest'] && $artifact->bucket === $prepared['artifact']->bucket
                            && $artifact->object_key === $prepared['artifact']->object_key && $artifact->object_id === $prepared['artifact']->object_id;
                    } catch (Throwable) {
                        $same = false;
                    }
                    if (! $same) {
                        $status = 'retry_required';
                        $reason = 'catalog_changed';
                    } elseif ($proof['action'] === 'updates.apply' && ! hash_equals($current['sha256'], $proof['package_sha256'])) {
                        $status = 'denied';
                        $reason = 'package_mismatch';
                    } elseif ($proof['action'] === 'updates.download') {
                        $urlDeadline = isset($prepared['download']['expires_at']) ? CarbonImmutable::parse($prepared['download']['expires_at'])->timestamp : 0;
                        $licenseDeadline = CarbonImmutable::parse($decision['license']->expires_at, 'UTC')->timestamp;
                        if ($urlDeadline <= $issuedAt || $urlDeadline > $licenseDeadline) {
                            $status = 'retry_required';
                            $reason = 'authorization_changed';
                        } else {
                            $manifest = $current;
                            $download = ['url' => $prepared['download']['download_url'], 'expires_at' => $urlDeadline,
                                'expires_in_seconds' => $urlDeadline - $issuedAt];
                            $deadline = $urlDeadline;
                            $status = 'authorized';
                        }
                    } else {
                        $manifest = $current;
                        $status = $proof['action'] === 'updates.check' ? 'available' : 'authorized';
                    }
                }
            }
            if (in_array($status, ['denied', 'retry_required', 'unavailable'], true)) {
                $deadline = $issuedAt;
            }
            $authorization = (string) Str::uuid();
            $claims = ['protocol' => 'shadai-desktop-updates', 'version' => 1, 'action' => $proof['action'],
                'authorization_id' => $authorization, 'status' => $status, 'reason' => $reason,
                'product' => $proof['product'], 'activation_id' => $proof['activation_id'], 'device_hash' => $proof['device_hash'],
                'key_hash' => $proof['key_hash'], 'request_nonce' => $proof['nonce'], 'architecture' => $proof['architecture'],
                'current_version' => $proof['current_version'], 'api_contract' => (int) $proof['api_contract'], 'schema_version' => (int) $proof['schema_version'],
                'issued_at' => $issuedAt, 'expires_at' => $deadline, 'release' => $manifest, 'download' => $download];
            $signed = $this->authority->sign($claims);
            DB::table('desktop_update_events')->insert(['authorization_id' => $authorization, 'request_nonce' => $proof['nonce'],
                'activation_id' => $proof['activation_id'], 'license_id' => $decision['license']?->id,
                'action' => $proof['action'], 'status' => $status, 'reason' => $reason, 'product' => $proof['product'],
                'architecture' => $proof['architecture'], 'current_version' => $proof['current_version'],
                'api_contract' => $proof['api_contract'], 'schema_version' => $proof['schema_version'],
                'release_id' => $manifest['release_id'] ?? null, 'release_revision' => $manifest['revision'] ?? null,
                'expires_at' => CarbonImmutable::createFromTimestamp($deadline, 'UTC'), 'created_at' => now('UTC')]);

            return ['envelope' => $signed, 'status_code' => $status === 'unavailable' ? 503 : 200];
        }, 3);
    }

    private function consumeNonce(string $nonce): void
    {
        try {
            // Commit independently: even a failed download attempt consumes its proof.
            DB::transaction(function () use ($nonce): void {
                DB::table('desktop_license_nonces')->where('expires_at', '<', now('UTC'))->delete();
                DB::table('desktop_license_nonces')->insert(['nonce' => $nonce, 'expires_at' => now('UTC')->addMinutes(10)]);
            });
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                abort(409, 'La solicitud ya fue utilizada. Genera una prueba con un nonce nuevo.');
            }
            throw $error;
        }
    }

    private function decide(array $proof, bool $lock): array
    {
        $track = DB::table('desktop_release_tracks')->where('product', $proof['product'])->where('architecture', $proof['architecture'])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $heads = DB::table('desktop_releases')->whereIn('id', array_filter([$track?->stable_release_id, $track?->pilot_release_id]))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $locator = DB::table('desktop_license_devices')->where('id', $proof['activation_id'])->first();
        $license = DB::table('desktop_licenses')->where('id', $locator?->license_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $device = DB::table('desktop_license_devices')->where('id', $proof['activation_id'])->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $context = ['license' => $license, 'release' => null];
        $reason = ! $license || $license->product !== $proof['product'] ? 'license_invalid'
            : (! $license->enabled ? 'license_disabled' : (! CarbonImmutable::parse($license->expires_at, 'UTC')->isFuture() ? 'license_expired' : ''));
        if ($reason === '' && (! $device || $device->license_id !== $license->id || $device->revoked_at !== null
            || ! hash_equals($device->key_hash, $proof['key_hash']) || ! hash_equals($device->device_hash, $proof['device_hash']))) {
            $reason = 'device_revoked_or_mismatch';
        }
        if ($reason !== '') {
            return ['status' => 'denied', 'reason' => $reason] + $context;
        }
        $installed = $this->catalog->versionSort($proof['current_version']);
        $eligible = $heads->filter(function ($release) use ($proof, $track, $installed): bool {
            if ($release->product !== $proof['product'] || $release->architecture !== $proof['architecture']
                || strcmp($release->version_sort, $installed) <= 0 || $release->min_api_contract > $proof['api_contract']
                || $release->min_schema_version > $proof['schema_version']
                || ($release->max_schema_version !== null && $release->max_schema_version < $proof['schema_version'])) {
                return false;
            }
            if ($release->id === $track->stable_release_id && $release->status === 'stable') {
                return true;
            }

            return $release->id === $track->pilot_release_id && $release->status === 'pilot'
                && DB::table('desktop_release_pilot_devices')->where('release_id', $release->id)->where('activation_id', $proof['activation_id'])->exists();
        })->sortByDesc('version_sort')->first();
        if (! $eligible) {
            return ['status' => 'no_update', 'reason' => ''] + $context;
        }
        if ($proof['action'] !== 'updates.check' && ($eligible->id !== $proof['release_id'] || (int) $eligible->revision !== (int) $proof['release_revision'])) {
            return ['status' => 'denied', 'reason' => 'release_changed'] + $context;
        }

        return ['status' => 'available', 'reason' => '', 'license' => $license, 'release' => $eligible];
    }
}
