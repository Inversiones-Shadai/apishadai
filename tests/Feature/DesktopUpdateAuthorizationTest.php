<?php

namespace Tests\Feature;

use App\Http\Middleware\HeadquartersPosSignatureMiddleware;
use App\Services\DesktopUpdates\DesktopReleaseArtifact;
use App\Services\DesktopUpdates\DesktopReleaseCatalog;
use App\Services\DesktopUpdates\DesktopUpdatePackage;
use App\Services\DesktopUpdates\DesktopUpdateStorage;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesktopUpdateAuthorizationTest extends TestCase
{
    private static string $privateKey = '';

    private static string $publicKey;

    private static string $otherPrivate = '';

    private static string $otherPublic;

    private static string $keyPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$privateKey);
        self::$publicKey = openssl_pkey_get_details($key)['key'];
        $other = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, self::$otherPrivate);
        self::$otherPublic = openssl_pkey_get_details($other)['key'];
        self::$keyPath = tempnam(sys_get_temp_dir(), 'updates-test-');
        file_put_contents(self::$keyPath, self::$privateKey);
    }

    public static function tearDownAfterClass(): void
    {
        unlink(self::$keyPath);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'licensing.private_key_path' => self::$keyPath, 'desktop_updates.enabled' => true, 'desktop_updates.api_enabled' => true,
            'filesystems.disks.desktop_updates.driver' => 's3', 'filesystems.disks.desktop_updates.bucket' => 'synthetic-updates',
            'filesystems.disks.desktop_updates.visibility' => 'private', 'filesystems.disks.desktop_updates.endpoint' => 'https://cellar.test']);
        DB::purge('sqlite');
        $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'));
        (require database_path('migrations/2026_10_26_154927_create_desktop_licensing_tables.php'))->up();
        (require database_path('migrations/2026_10_28_000001_create_desktop_release_catalog.php'))->up();
        (require database_path('migrations/2026_10_29_000001_create_desktop_update_events.php'))->up();
        (require database_path('migrations/2026_10_29_000002_create_desktop_update_installation_reports.php'))->up();
    }

    private function device(string $product = 'shpos', bool $other = false): array
    {
        $license = (string) Str::uuid();
        $id = (string) Str::uuid();
        $hash = str_repeat($other ? 'b' : 'a', 64);
        $publicKey = $other ? self::$otherPublic : self::$publicKey;
        DB::table('desktop_licenses')->insert(['id' => $license, 'label' => 'Synthetic update license', 'product' => $product,
            'key_hash' => hash('sha256', $license), 'key_suffix' => 'SYNTHETC', 'seats' => 1, 'enabled' => true,
            'expires_at' => now('UTC')->addMonth(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('desktop_license_devices')->insert(['id' => $id, 'license_id' => $license, 'device_name' => 'Synthetic PC',
            'device_hash' => $hash, 'key_hash' => hash('sha256', $publicKey), 'public_key' => $publicKey,
            'active_identity' => hash('sha256', $product.'|'.$hash), 'last_validated_at' => now('UTC'),
            'reserved_until' => now('UTC')->addDays(3), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return ['id' => $id, 'license_id' => $license, 'product' => $product];
    }

    private function release(array $changes = [], bool $current = true): object
    {
        $id = (string) Str::uuid();
        $artifactId = (string) Str::uuid();
        $row = array_replace(['id' => $id, 'artifact_id' => $artifactId, 'product' => 'shpos', 'version' => '1.10.0.0',
            'architecture' => 'x64', 'status' => 'stable', 'revision' => 3, 'notes' => 'Synthetic release notes',
            'min_api_contract' => 1, 'min_schema_version' => 258, 'max_schema_version' => 260, 'required' => false, 'created_by' => 7,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')], $changes);
        $metadata = ['SchemaVersion' => 1, 'Product' => $row['product'], 'Version' => $row['version'], 'Architecture' => $row['architecture'],
            'Sha256' => str_repeat('a', 64), 'SizeBytes' => 42, 'PackageName' => config('desktop_updates.products.'.$row['product'].'.name'),
            'Publisher' => 'CN=USER', 'HasSignature' => true, 'DistributionEligible' => true];
        $objectId = bin2hex(random_bytes(32));
        DB::table('desktop_release_artifacts')->insert(['id' => $artifactId, 'product' => $row['product'], 'version' => $row['version'],
            'architecture' => $row['architecture'], 'bucket' => 'synthetic-updates', 'object_id' => $objectId,
            'object_key' => app(DesktopUpdateStorage::class)->key(DesktopReleaseArtifact::fromArray($metadata), $objectId),
            'sha256' => $metadata['Sha256'], 'size_bytes' => 42, 'package_name' => $metadata['PackageName'], 'publisher' => 'CN=USER',
            'metadata' => json_encode($metadata), 'verified_at' => now('UTC'), 'created_at' => now('UTC')]);
        DB::table('desktop_releases')->insert($row + ['version_sort' => app(DesktopReleaseCatalog::class)->versionSort($row['version'])]);
        if ($current && in_array($row['status'], ['pilot', 'stable'], true)) {
            DB::table('desktop_release_tracks')->where('product', $row['product'])->where('architecture', $row['architecture'])
                ->update([$row['status'].'_release_id' => $id]);
        }

        return DB::table('desktop_releases')->where('id', $id)->first();
    }

    private function downloads(int $count = 0, ?callable $callback = null): void
    {
        $packages = Mockery::mock(DesktopUpdatePackage::class, [app(DesktopUpdateStorage::class)])->makePartial();
        $packages->shouldReceive('download')->times($count)->andReturnUsing(function (object $artifact, DateTimeImmutable $notAfter) use ($callback): array {
            $this->assertSame(0, DB::transactionLevel());
            if ($callback) {
                $callback();
            }
            $deadline = min(now('UTC')->timestamp + 3600, $notAfter->getTimestamp());

            return ['download_url' => 'https://cellar.test/synthetic.msix?signature=synthetic',
                'expires_at' => CarbonImmutable::createFromTimestamp($deadline, 'UTC')->toIso8601String(), 'expires_in_seconds' => $deadline - now('UTC')->timestamp];
        });
        $this->app->instance(DesktopUpdatePackage::class, $packages);
    }

    private function proof(array $device, string $action = 'check', array $changes = [], bool $otherKey = false): array
    {
        $data = array_replace(['action' => 'updates.'.$action, 'product' => $device['product'], 'activation_id' => $device['id'],
            'device_hash' => str_repeat('a', 64), 'device_name' => 'Synthetic PC', 'public_key' => $otherKey ? self::$otherPublic : self::$publicKey,
            'timestamp' => now('UTC')->timestamp, 'nonce' => (string) Str::uuid(), 'current_version' => '1.9.0.0',
            'architecture' => 'x64', 'api_contract' => 1, 'schema_version' => 258], $changes);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        openssl_sign($json, $signature, $otherKey ? self::$otherPrivate : self::$privateKey, OPENSSL_ALGO_SHA256);

        return ['payload' => base64_encode($json), 'signature' => base64_encode($signature)];
    }

    private function exchange(array $device, string $action = 'check', array $changes = [], bool $otherKey = false): array
    {
        $response = $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class)
            ->postJson('/api/headquarters/updates/'.$action, $this->proof($device, $action, $changes, $otherKey))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        return $this->claims($response->json('data'));
    }

    private function claims(array $signed): array
    {
        $this->assertSame(1, openssl_verify($signed['payload'], base64_decode($signed['signature']), self::$publicKey, OPENSSL_ALGO_SHA256));
        $claims = json_decode(base64_decode($signed['payload']), true);
        $this->assertSame('shadai-desktop-updates', $claims['protocol']);

        return $claims;
    }

    public static function products(): array
    {
        return ['ShPOS' => ['shpos'], 'Administrator' => ['shpos-administrator'], 'HQ-Client' => ['hq-client']];
    }

    #[DataProvider('products')]
    public function test_check_returns_signed_metadata_for_current_stable_without_a_url(string $product): void
    {
        $device = $this->device($product);
        $release = $this->release(['product' => $product]);
        $this->downloads();
        $claims = $this->exchange($device, changes: ['nonce' => '10000000-0000-4000-8000-000000000001']);
        $this->assertSame('available', $claims['status']);
        $this->assertSame($product, $claims['product']);
        $this->assertSame($product, $claims['release']['product']);
        $this->assertSame($release->id, $claims['release']['release_id']);
        $this->assertSame('1.10.0.0', $claims['release']['version']);
        $this->assertSame(1793275200, $claims['issued_at']);
        $this->assertSame(1793275260, $claims['expires_at']);
        $this->assertSame('10000000-0000-4000-8000-000000000001', $claims['request_nonce']);
        $this->assertNull($claims['download']);
        $this->assertArrayNotHasKey('object_key', $claims['release']);
        $this->assertDatabaseHas('desktop_update_events', ['activation_id' => $device['id'], 'release_id' => $release->id, 'status' => 'available']);
        $this->assertDatabaseCount('desktop_license_devices', 1);
    }

    public function test_download_and_renewal_revalidate_and_do_not_log_the_url(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads(2);
        $target = ['release_id' => $release->id, 'release_revision' => 3];
        $first = $this->exchange($device, 'download', $target);
        $this->travel(61)->minutes();
        $second = $this->exchange($device, 'download', $target);
        $this->assertSame('authorized', $first['status']);
        $this->assertSame(3600, $first['download']['expires_in_seconds']);
        $this->assertGreaterThan($first['expires_at'], $second['expires_at']);
        $this->assertNotSame($first['request_nonce'], $second['request_nonce']);
        $this->assertStringNotContainsString('signature=', json_encode(DB::table('desktop_update_events')->get()));
        $this->assertDatabaseCount('desktop_update_events', 2);
    }

    public function test_apply_grant_is_short_lived_bound_to_hash_and_does_not_include_download_url(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads();
        $claims = $this->exchange($device, 'apply', ['release_id' => $release->id, 'release_revision' => 3, 'package_sha256' => str_repeat('a', 64)]);
        $this->assertSame('authorized', $claims['status']);
        $this->assertSame('updates.apply', $claims['action']);
        $this->assertSame(60, $claims['expires_at'] - $claims['issued_at']);
        $this->assertNull($claims['download']);
        $this->assertArrayNotHasKey('offline_until', $claims);
    }

    public static function deniedCases(): array
    {
        return ['disabled' => ['disabled', 'license_disabled'], 'expired' => ['expired', 'license_expired'],
            'revoked' => ['revoked', 'device_revoked_or_mismatch'], 'product' => ['product', 'license_invalid'],
            'device' => ['device', 'device_revoked_or_mismatch'], 'key' => ['key', 'device_revoked_or_mismatch'],
            'foreign activation' => ['foreign', 'device_revoked_or_mismatch'], 'unknown activation' => ['unknown', 'license_invalid']];
    }

    #[DataProvider('deniedCases')]
    public function test_denies_invalid_license_or_device_without_manifest_or_url(string $case, string $reason): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads();
        $changes = ['release_id' => $release->id, 'release_revision' => 3];
        match ($case) {
            'disabled' => DB::table('desktop_licenses')->where('id', $device['license_id'])->update(['enabled' => false]),
            'expired' => DB::table('desktop_licenses')->where('id', $device['license_id'])->update(['expires_at' => now('UTC')]),
            'revoked' => DB::table('desktop_license_devices')->where('id', $device['id'])->update(['revoked_at' => now('UTC')]),
            'product' => $changes['product'] = 'shpos-administrator',
            'device' => $changes['device_hash'] = str_repeat('f', 64),
            'foreign' => $changes['activation_id'] = $this->device(other: true)['id'],
            'unknown' => $changes['activation_id'] = (string) Str::uuid(),
            default => null,
        };
        $claims = $this->exchange($device, 'download', $changes, $case === 'key');
        $this->assertSame('denied', $claims['status']);
        $this->assertSame($reason, $claims['reason']);
        $this->assertNull($claims['release']);
        $this->assertNull($claims['download']);
        $this->assertSame($claims['issued_at'], $claims['expires_at']);
    }

    public function test_only_assigned_pilot_gets_current_pilot_and_others_get_stable(): void
    {
        $pilotDevice = $this->device();
        $other = $this->device(other: true);
        $stable = $this->release(['version' => '1.9.1.0']);
        $pilot = $this->release(['version' => '1.10.0.0', 'status' => 'pilot']);
        DB::table('desktop_release_pilot_devices')->insert(['release_id' => $pilot->id, 'activation_id' => $pilotDevice['id']]);
        $this->downloads();
        $this->assertSame($pilot->id, $this->exchange($pilotDevice)['release']['release_id']);
        $this->assertSame($stable->id, $this->exchange($other, changes: ['device_hash' => str_repeat('b', 64)], otherKey: true)['release']['release_id']);
    }

    public static function incompatibleCases(): array
    {
        return ['architecture' => [['architecture' => 'arm64']], 'schema too old' => [['schema_version' => 257]],
            'schema too new' => [['schema_version' => 261]], 'installed equal' => [['current_version' => '1.10.0.0']],
            'installed newer' => [['current_version' => '2.0.0.0']]];
    }

    #[DataProvider('incompatibleCases')]
    public function test_returns_no_update_for_incompatible_or_non_increasing_release(array $changes): void
    {
        $device = $this->device();
        $this->release();
        $this->downloads();
        $claims = $this->exchange($device, changes: $changes);
        $this->assertSame('no_update', $claims['status']);
        $this->assertNull($claims['release']);
        $this->assertNull($claims['download']);
    }

    public function test_incompatible_api_contract_and_historical_release_are_never_served(): void
    {
        $device = $this->device();
        $this->release(['version' => '1.9.1.0'], false);
        $this->release(['min_api_contract' => 2]);
        $this->downloads();
        $this->assertSame('no_update', $this->exchange($device)['status']);
    }

    public function test_draft_and_withdrawn_records_do_not_authorize_even_when_track_is_stale(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads();
        DB::table('desktop_releases')->where('id', $release->id)->update(['status' => 'draft']);
        $this->assertSame('no_update', $this->exchange($device)['status']);
        DB::table('desktop_releases')->where('id', $release->id)->update(['status' => 'withdrawn']);
        $this->assertSame('no_update', $this->exchange($device)['status']);
    }

    public function test_cannot_request_historical_package_or_stale_release_revision(): void
    {
        $device = $this->device();
        $old = $this->release(['version' => '1.9.1.0'], false);
        $current = $this->release();
        $this->downloads();
        $this->assertSame('release_changed', $this->exchange($device, 'download', ['release_id' => $old->id, 'release_revision' => 3])['reason']);
        $this->assertSame('release_changed', $this->exchange($device, 'download', ['release_id' => $current->id, 'release_revision' => 2])['reason']);
    }

    public function test_apply_rejects_different_cached_package_hash(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads();
        $claims = $this->exchange($device, 'apply', ['release_id' => $release->id, 'release_revision' => 3, 'package_sha256' => str_repeat('b', 64)]);
        $this->assertSame('package_mismatch', $claims['reason']);
        $this->assertNull($claims['release']);
    }

    public function test_license_revocation_during_remote_verification_discards_prepared_url(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads(1, fn () => DB::table('desktop_license_devices')->where('id', $device['id'])->update(['revoked_at' => now('UTC')]));
        $claims = $this->exchange($device, 'download', ['release_id' => $release->id, 'release_revision' => 3]);
        $this->assertSame('denied', $claims['status']);
        $this->assertNull($claims['download']);
        $this->assertDatabaseHas('desktop_update_events', ['status' => 'denied', 'release_id' => null]);
    }

    public function test_release_withdrawal_during_remote_verification_discards_prepared_url(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads(1, fn () => DB::table('desktop_releases')->where('id', $release->id)->update(['status' => 'withdrawn']));
        $claims = $this->exchange($device, 'download', ['release_id' => $release->id, 'release_revision' => 3]);
        $this->assertSame('no_update', $claims['status']);
        $this->assertNull($claims['download']);
    }

    public function test_short_license_caps_url_and_apply_grant_and_expired_license_cannot_renew(): void
    {
        $device = $this->device();
        $release = $this->release();
        DB::table('desktop_licenses')->where('id', $device['license_id'])->update(['expires_at' => now('UTC')->addSeconds(20)]);
        $this->downloads(1);
        $target = ['release_id' => $release->id, 'release_revision' => 3];
        $this->assertSame(20, $this->exchange($device, 'download', $target)['download']['expires_in_seconds']);
        $grant = $this->exchange($device, 'apply', $target + ['package_sha256' => str_repeat('a', 64)]);
        $this->assertSame(20, $grant['expires_at'] - $grant['issued_at']);
        $this->travel(20)->seconds();
        $denied = $this->exchange($device, 'download', $target);
        $this->assertSame('license_expired', $denied['reason']);
        $this->assertNull($denied['download']);
    }

    public function test_replay_does_not_issue_another_authorization_or_event(): void
    {
        $device = $this->device();
        $this->release();
        $this->downloads();
        $envelope = $this->proof($device);
        $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class)->postJson('/api/headquarters/updates/check', $envelope)->assertOk();
        $this->postJson('/api/headquarters/updates/check', $envelope)->assertConflict();
        $this->assertDatabaseCount('desktop_update_events', 1);
        $this->assertDatabaseCount('desktop_license_nonces', 1);
    }

    public function test_storage_failure_is_audited_without_secrets_and_still_consumes_nonce(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads(1, fn () => throw new \RuntimeException('https://private.test/?secret=do-not-expose'));
        $envelope = $this->proof($device, 'download', ['release_id' => $release->id, 'release_revision' => 3]);
        $response = $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class)->postJson('/api/headquarters/updates/download', $envelope)
            ->assertServiceUnavailable()->assertDontSee('do-not-expose');
        $this->assertNull($this->claims($response->json('data'))['download']);
        $this->assertDatabaseHas('desktop_update_events', ['status' => 'unavailable', 'reason' => 'package_unavailable']);
        $this->postJson('/api/headquarters/updates/download', $envelope)->assertConflict();
    }

    public function test_modified_private_reference_never_authorizes_a_download(): void
    {
        $device = $this->device();
        $release = $this->release();
        DB::table('desktop_release_artifacts')->where('id', $release->artifact_id)->update(['object_key' => 'desktop-updates/other.msix']);
        $this->downloads();
        $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class)->postJson('/api/headquarters/updates/download',
            $this->proof($device, 'download', ['release_id' => $release->id, 'release_revision' => 3]))->assertServiceUnavailable();
    }

    public function test_rejects_tampered_signature_stale_clock_unknown_fields_and_version_overflow(): void
    {
        $device = $this->device();
        $this->downloads();
        $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class);
        $bad = $this->proof($device);
        $bad['signature'] = base64_encode(str_repeat('x', 384));
        $this->postJson('/api/headquarters/updates/check', $bad)->assertForbidden();
        $this->postJson('/api/headquarters/updates/check', $this->proof($device, changes: ['timestamp' => now('UTC')->subMinutes(6)->timestamp]))->assertUnprocessable();
        $this->postJson('/api/headquarters/updates/check', $this->proof($device, changes: ['object_key' => 'private.msix']))->assertUnprocessable();
        $this->postJson('/api/headquarters/updates/check', $this->proof($device, changes: ['current_version' => '1.99999.0.0']))->assertUnprocessable();
        $this->assertDatabaseCount('desktop_update_events', 0);
        $this->assertDatabaseCount('desktop_license_nonces', 0);
    }

    public function test_disabled_feature_and_missing_server_signing_key_never_authorize(): void
    {
        $device = $this->device();
        $this->downloads();
        $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class);
        config(['desktop_updates.api_enabled' => false]);
        $this->postJson('/api/headquarters/updates/check', $this->proof($device))->assertServiceUnavailable();
        config(['desktop_updates.api_enabled' => true, 'licensing.private_key_path' => self::$keyPath.'.missing']);
        $this->postJson('/api/headquarters/updates/check', $this->proof($device))->assertServiceUnavailable();
        $this->assertDatabaseCount('desktop_update_events', 0);
        $this->assertDatabaseCount('desktop_license_nonces', 0);
    }

    public function test_central_route_requires_signed_hq_channel_even_with_valid_device_proof(): void
    {
        $device = $this->device();
        $this->downloads();
        $this->postJson('/api/headquarters/updates/check', $this->proof($device))->assertUnauthorized();
        $this->assertDatabaseCount('desktop_update_events', 0);
    }

    public function test_hmac_authenticated_hq_request_reaches_device_authorization_without_login(): void
    {
        $device = $this->device();
        $this->release();
        $this->downloads();
        Schema::create('hq_pos_request_nonces', function ($table): void {
            $table->uuid('nonce')->primary();
            $table->timestamp('expires_at');
        });
        config(['headquarters.secret' => str_repeat('s', 64), 'headquarters.key_id' => 'synthetic-hq', 'headquarters.pos_sender_ips' => ['127.0.0.1']]);
        $body = json_encode($this->proof($device), JSON_UNESCAPED_SLASHES);
        $path = '/api/headquarters/updates/check';
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $canonical = "POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        $server = $this->transformHeadersToServerVars(['X-HQ-Key-Id' => 'synthetic-hq', 'X-HQ-Timestamp' => $timestamp, 'X-HQ-Nonce' => $nonce,
            'X-HQ-Signature' => hash_hmac('sha256', $canonical, str_repeat('s', 64)), 'Content-Type' => 'application/json', 'Accept' => 'application/json']);
        $response = $this->call('POST', $path, [], [], [], $server, $body)->assertOk();
        $this->assertSame('available', $this->claims($response->json('data'))['status']);
        $this->assertDatabaseCount('hq_pos_request_nonces', 1);
    }

    public static function lateLicenseChanges(): array
    {
        $cases = [];
        foreach (['shpos', 'shpos-administrator', 'hq-client'] as $product) {
            foreach (['disabled', 'expired', 'revoked', 'key'] as $change) {
                $cases[$product.' '.$change] = [$product, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('lateLicenseChanges')]
    public function test_all_products_revalidate_license_after_download_before_apply_or_renewal(string $product, string $change): void
    {
        $device = $this->device($product);
        $release = $this->release(['product' => $product]);
        $this->downloads(1);
        $target = ['release_id' => $release->id, 'release_revision' => 3];
        $this->assertSame('authorized', $this->exchange($device, 'download', $target)['status']);
        match ($change) {
            'disabled' => DB::table('desktop_licenses')->where('id', $device['license_id'])->update(['enabled' => false]),
            'expired' => DB::table('desktop_licenses')->where('id', $device['license_id'])->update(['expires_at' => now('UTC')]),
            'revoked' => DB::table('desktop_license_devices')->where('id', $device['id'])->update(['revoked_at' => now('UTC')]),
            'key' => DB::table('desktop_license_devices')->where('id', $device['id'])->update(['key_hash' => hash('sha256', self::$otherPublic)]),
        };
        foreach (['apply', 'download'] as $action) {
            $claims = $this->exchange($device, $action, $target + ($action === 'apply' ? ['package_sha256' => str_repeat('a', 64)] : []));
            $this->assertSame('denied', $claims['status']);
            $this->assertNull($claims['release']);
            $this->assertNull($claims['download']);
        }
        $this->assertDatabaseCount('desktop_update_installation_reports', 0);
    }

    public function test_revocation_after_download_blocks_apply_and_url_renewal(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads(1);
        $target = ['release_id' => $release->id, 'release_revision' => 3];
        $this->assertSame('authorized', $this->exchange($device, 'download', $target)['status']);
        DB::table('desktop_license_devices')->where('id', $device['id'])->update(['revoked_at' => now('UTC')]);
        $grant = $this->exchange($device, 'apply', $target + ['package_sha256' => str_repeat('a', 64)]);
        $renewal = $this->exchange($device, 'download', $target);
        $this->assertSame('denied', $grant['status']);
        $this->assertSame('denied', $renewal['status']);
        $this->assertNull($grant['download']);
        $this->assertNull($renewal['download']);
    }

    public function test_shortened_license_during_storage_check_does_not_disclose_longer_prepared_url(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads(1, fn () => DB::table('desktop_licenses')->where('id', $device['license_id'])->update(['expires_at' => now('UTC')->addSeconds(10)]));
        $claims = $this->exchange($device, 'download', ['release_id' => $release->id, 'release_revision' => 3]);
        $this->assertSame('retry_required', $claims['status']);
        $this->assertSame('authorization_changed', $claims['reason']);
        $this->assertNull($claims['download']);
    }

    public function test_removing_pilot_assignment_during_download_discards_the_pilot_url(): void
    {
        $device = $this->device();
        $release = $this->release(['status' => 'pilot']);
        DB::table('desktop_release_pilot_devices')->insert(['release_id' => $release->id, 'activation_id' => $device['id']]);
        $this->downloads(1, fn () => DB::table('desktop_release_pilot_devices')->where('release_id', $release->id)->delete());
        $claims = $this->exchange($device, 'download', ['release_id' => $release->id, 'release_revision' => 3]);
        $this->assertSame('no_update', $claims['status']);
        $this->assertNull($claims['download']);
    }

    public function test_device_rate_limit_rejects_extra_attempt_and_updates_do_not_extend_license_lease(): void
    {
        $device = $this->device();
        $this->downloads();
        config(['desktop_updates.requests_per_minute' => 1]);
        $before = DB::table('desktop_license_devices')->where('id', $device['id'])->first();
        $this->assertSame('no_update', $this->exchange($device)['status']);
        $this->postJson('/api/headquarters/updates/check', $this->proof($device))->assertTooManyRequests();
        $after = DB::table('desktop_license_devices')->where('id', $device['id'])->first();
        $this->assertSame($before->reserved_until, $after->reserved_until);
        $this->assertSame($before->last_validated_at, $after->last_validated_at);
        $this->assertDatabaseCount('desktop_update_events', 1);
    }

    public function test_installed_receipt_requires_prior_apply_grant_and_retries_do_not_duplicate(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads();
        $grant = $this->exchange($device, 'apply', ['release_id' => $release->id, 'release_revision' => 3, 'package_sha256' => str_repeat('a', 64)]);
        $fields = ['authorization_id' => $grant['authorization_id'], 'release_id' => $release->id, 'release_revision' => 3,
            'result' => 'installed', 'current_version' => '1.10.0.0', 'failure_code' => ''];
        $ack = $this->exchange($device, 'report', $fields);
        $this->assertSame('recorded', $ack['status']);
        $this->assertSame($grant['authorization_id'], $ack['authorization_id']);
        $this->assertNull($ack['download']);
        $this->assertNull($ack['release']);
        $this->exchange($device, 'report', $fields);
        $this->assertDatabaseCount('desktop_update_installation_reports', 1);
        $this->assertDatabaseHas('desktop_update_installation_reports', ['authorization_id' => $grant['authorization_id'], 'reported_version' => '1.10.0.0']);
        $this->assertDatabaseCount('desktop_update_events', 1);
    }

    public function test_revoked_or_expired_device_can_only_report_previous_installation_not_get_new_permission(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads();
        $grant = $this->exchange($device, 'apply', ['release_id' => $release->id, 'release_revision' => 3, 'package_sha256' => str_repeat('a', 64)]);
        DB::table('desktop_license_devices')->where('id', $device['id'])->update(['revoked_at' => now('UTC')]);
        DB::table('desktop_licenses')->where('id', $device['license_id'])->update(['enabled' => false, 'expires_at' => now('UTC')]);
        config(['desktop_updates.enabled' => false]);
        $ack = $this->exchange($device, 'report', ['authorization_id' => $grant['authorization_id'], 'release_id' => $release->id,
            'release_revision' => 3, 'result' => 'installed', 'current_version' => '1.10.0.0', 'failure_code' => '']);
        $this->assertSame('recorded', $ack['status']);
        $this->assertNull($ack['download']);
        $this->assertDatabaseCount('desktop_update_events', 1);
    }

    public function test_other_device_or_made_up_authorization_cannot_report_installation(): void
    {
        $device = $this->device();
        $other = $this->device(other: true);
        $release = $this->release();
        $this->downloads();
        $grant = $this->exchange($device, 'apply', ['release_id' => $release->id, 'release_revision' => 3, 'package_sha256' => str_repeat('a', 64)]);
        $fields = ['authorization_id' => $grant['authorization_id'], 'release_id' => $release->id, 'release_revision' => 3,
            'result' => 'installed', 'current_version' => '1.10.0.0', 'failure_code' => ''];
        $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class)->postJson('/api/headquarters/updates/report',
            $this->proof($other, 'report', $fields + ['device_hash' => str_repeat('b', 64)], true))->assertForbidden();
        $fields['authorization_id'] = (string) Str::uuid();
        $this->postJson('/api/headquarters/updates/report', $this->proof($device, 'report', $fields))->assertForbidden();
        $this->assertDatabaseCount('desktop_update_installation_reports', 0);
    }

    public function test_installed_receipt_must_match_target_version_and_original_result_cannot_be_rewritten(): void
    {
        $device = $this->device();
        $release = $this->release();
        $this->downloads();
        $grant = $this->exchange($device, 'apply', ['release_id' => $release->id, 'release_revision' => 3, 'package_sha256' => str_repeat('a', 64)]);
        $fields = ['authorization_id' => $grant['authorization_id'], 'release_id' => $release->id, 'release_revision' => 3,
            'result' => 'installed', 'current_version' => '1.9.0.0', 'failure_code' => ''];
        $this->withoutMiddleware(HeadquartersPosSignatureMiddleware::class)->postJson('/api/headquarters/updates/report', $this->proof($device, 'report', $fields))->assertUnprocessable();
        $fields['result'] = 'failed';
        $fields['failure_code'] = 'update_not_completed';
        $this->assertSame('recorded', $this->exchange($device, 'report', $fields)['status']);
        $fields['result'] = 'installed';
        $fields['current_version'] = '1.10.0.0';
        $fields['failure_code'] = '';
        $this->postJson('/api/headquarters/updates/report', $this->proof($device, 'report', $fields))->assertConflict();
        $this->assertDatabaseHas('desktop_update_installation_reports', ['result' => 'failed', 'reported_version' => '1.9.0.0']);
    }
}
