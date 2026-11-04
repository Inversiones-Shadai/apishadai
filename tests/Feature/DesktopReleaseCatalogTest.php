<?php

namespace Tests\Feature;

use App\Services\DesktopUpdates\DesktopArtifactRegistry;
use App\Services\DesktopUpdates\DesktopReleaseCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DesktopReleaseCatalogTest extends TestCase
{
    private DesktopReleaseCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_26_154927_create_desktop_licensing_tables.php'))->up();
        (require database_path('migrations/2026_10_28_000001_create_desktop_release_catalog.php'))->up();
        $registry = Mockery::mock(DesktopArtifactRegistry::class);
        $registry->shouldReceive('verify')->andReturnNull()->byDefault();
        $this->app->instance(DesktopArtifactRegistry::class, $registry);
        $this->catalog = app(DesktopReleaseCatalog::class);
    }

    private function artifact(string $version = '1.0.1.0', string $product = 'shpos'): string
    {
        $id = (string) Str::uuid();
        DB::table('desktop_release_artifacts')->insert(['id' => $id, 'product' => $product, 'version' => $version,
            'architecture' => 'x64', 'bucket' => 'synthetic-updates', 'object_id' => bin2hex(random_bytes(32)),
            'object_key' => 'synthetic/private.msix', 'sha256' => str_repeat('a', 64), 'size_bytes' => 100,
            'package_name' => config('desktop_updates.products.'.$product.'.name'), 'publisher' => 'CN=USER',
            'metadata' => '{}', 'verified_at' => now('UTC'), 'created_at' => now('UTC')]);

        return $id;
    }

    private function draft(?string $artifact = null, array $overrides = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'artifact_id' => $artifact ?? $this->artifact(),
            'notes' => 'Synthetic release notes', 'min_api_contract' => 1, 'min_schema_version' => 258,
            'max_schema_version' => null, 'required' => false], $overrides);
    }

    private function device(string $product = 'shpos', bool $enabled = true, bool $revoked = false): string
    {
        $license = (string) Str::uuid();
        $device = (string) Str::uuid();
        DB::table('desktop_licenses')->insert(['id' => $license, 'label' => 'Synthetic license', 'product' => $product,
            'key_hash' => bin2hex(random_bytes(32)), 'key_suffix' => 'SYNTHETC', 'seats' => 1, 'enabled' => $enabled,
            'expires_at' => now('UTC')->addMonth(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('desktop_license_devices')->insert(['id' => $device, 'license_id' => $license, 'device_name' => 'SYNTHETIC',
            'device_hash' => bin2hex(random_bytes(32)), 'key_hash' => bin2hex(random_bytes(32)), 'public_key' => 'synthetic-only',
            'active_identity' => bin2hex(random_bytes(32)), 'revoked_at' => $revoked ? now('UTC') : null,
            'last_validated_at' => now('UTC'), 'reserved_until' => now('UTC')->addDays(3), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return $device;
    }

    private function change(array $release, string $action, array $fields = []): array
    {
        return $this->catalog->change($release['id'], $action,
            ['request_id' => (string) Str::uuid(), 'expected_revision' => $release['revision']] + $fields, 7);
    }

    private function expectHttp(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Se esperaba rechazo HTTP '.$status);
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function pilot(string $version): array
    {
        $release = $this->catalog->create($this->draft($this->artifact($version)), 7);
        $release = $this->change($release, 'pilots', ['activation_ids' => [$this->device()]]);

        return $this->change($release, 'pilot');
    }

    public function test_draft_is_bound_to_verified_artifact_and_request_is_idempotent(): void
    {
        $data = $this->draft();
        $first = $this->catalog->create($data, 7);
        $this->assertSame($first, $this->catalog->create($data, 7));
        $this->assertSame($first, $this->catalog->create(array_reverse($data, true), 7));
        $this->assertSame('draft', $first['status']);
        $this->assertDatabaseCount('desktop_releases', 1);
        $this->assertDatabaseCount('desktop_release_events', 1);
        $this->assertDatabaseCount('desktop_release_operations', 1);
        $this->expectHttp(409, fn () => $this->catalog->create(array_replace($data, ['notes' => 'Changed']), 7));
        $this->expectHttp(409, fn () => $this->catalog->create($data, 8));
    }

    public function test_duplicate_version_does_not_create_another_release(): void
    {
        $this->catalog->create($this->draft(), 7);
        $this->expectHttp(409, fn () => $this->catalog->create($this->draft(), 7));
        $this->assertDatabaseCount('desktop_releases', 1);
        $this->assertDatabaseCount('desktop_release_operations', 1);
    }

    public function test_cannot_skip_pilot_or_publish_without_eligible_targets(): void
    {
        $release = $this->catalog->create($this->draft(), 7);
        $this->expectHttp(409, fn () => $this->change($release, 'stable'));
        $this->expectHttp(422, fn () => $this->change($release, 'pilot'));
        $this->assertSame('draft', $this->catalog->record($release['id'])->status);
    }

    public function test_only_correct_product_active_licenses_and_unrevoked_devices_are_eligible(): void
    {
        $release = $this->catalog->create($this->draft(), 7);
        foreach ([$this->device('hq-client'), $this->device('shpos', false), $this->device('shpos', true, true)] as $id) {
            $this->expectHttp(422, fn () => $this->change($release, 'pilots', ['activation_ids' => [$id]]));
        }
        $expired = $this->device();
        DB::table('desktop_licenses')->where('id', DB::table('desktop_license_devices')->where('id', $expired)->value('license_id'))->update(['expires_at' => now('UTC')->subMinute()]);
        $this->expectHttp(422, fn () => $this->change($release, 'pilots', ['activation_ids' => [$expired]]));
        $this->assertDatabaseCount('desktop_release_pilot_devices', 0);
    }

    public function test_publish_pilot_then_stable_and_retire_preserves_artifact_and_audit(): void
    {
        $pilot = $this->pilot('2.0.0.0');
        $this->assertSame('pilot', $pilot['status']);
        $stable = $this->change($pilot, 'stable');
        $this->assertDatabaseHas('desktop_release_tracks', ['product' => 'shpos', 'architecture' => 'x64', 'stable_release_id' => $stable['id'], 'pilot_release_id' => null]);
        $withdrawn = $this->change($stable, 'withdraw', ['reason' => 'Synthetic withdrawal']);
        $this->assertSame('withdrawn', $withdrawn['status']);
        $this->assertDatabaseHas('desktop_release_tracks', ['product' => 'shpos', 'architecture' => 'x64', 'stable_release_id' => null, 'highest_stable_version' => '00002.00000.00000.00000']);
        $this->assertDatabaseCount('desktop_release_artifacts', 1);
        $this->assertDatabaseCount('desktop_release_events', 5);
        $event = json_decode(DB::table('desktop_release_events')->orderByDesc('id')->value('snapshot'), true);
        $this->assertSame('Synthetic withdrawal', $event['reason']);
    }

    public function test_revision_conflict_rolls_back_and_preserves_previous_pilot_selection(): void
    {
        $release = $this->catalog->create($this->draft(), 7);
        $device = $this->device();
        $updated = $this->change($release, 'pilots', ['activation_ids' => [$device]]);
        $this->expectHttp(409, fn () => $this->change($release, 'pilots', ['activation_ids' => []]));
        $this->assertSame($updated['revision'], (int) $this->catalog->record($release['id'])->revision);
        $this->assertDatabaseHas('desktop_release_pilot_devices', ['release_id' => $release['id'], 'activation_id' => $device]);
        $this->assertDatabaseCount('desktop_release_operations', 2);
    }

    public function test_license_is_revalidated_when_promoting_existing_pilot_assignment(): void
    {
        $release = $this->catalog->create($this->draft(), 7);
        $device = $this->device();
        $release = $this->change($release, 'pilots', ['activation_ids' => [$device]]);
        DB::table('desktop_license_devices')->where('id', $device)->update(['revoked_at' => now('UTC')]);
        $this->expectHttp(422, fn () => $this->change($release, 'pilot'));
    }

    public function test_retiring_stable_does_not_allow_a_lower_version_to_be_promoted(): void
    {
        $stable = $this->change($this->pilot('2.0.0.0'), 'stable');
        $this->change($stable, 'withdraw', ['reason' => 'Test withdrawal']);
        $older = $this->catalog->create($this->draft($this->artifact('1.99.99.99')), 7);
        $older = $this->change($older, 'pilots', ['activation_ids' => [$this->device()]]);
        $this->expectHttp(409, fn () => $this->change($older, 'pilot'));
        $this->assertSame('pilot', $this->pilot('2.0.0.1')['status']);
    }

    public function test_only_current_pilot_can_be_promoted_to_stable(): void
    {
        $first = $this->pilot('1.0.1.0');
        $second = $this->pilot('1.0.2.0');
        $this->expectHttp(409, fn () => $this->change($first, 'stable'));
        $this->assertSame('stable', $this->change($second, 'stable')['status']);
    }

    public function test_published_metadata_cannot_be_edited(): void
    {
        $pilot = $this->pilot('1.0.1.0');
        $this->expectHttp(409, fn () => $this->change($pilot, 'edit', ['notes' => 'Cannot change published notes']));
        $this->assertSame('Synthetic release notes', $this->catalog->record($pilot['id'])->notes);
    }

    public function test_a_replayed_transition_does_not_reverify_storage_or_append_events(): void
    {
        $release = $this->catalog->create($this->draft(), 7);
        $release = $this->change($release, 'pilots', ['activation_ids' => [$this->device()]]);
        $data = ['request_id' => (string) Str::uuid(), 'expected_revision' => $release['revision']];
        $registry = Mockery::mock(DesktopArtifactRegistry::class);
        $registry->shouldReceive('verify')->once()->andReturnNull();
        $this->app->instance(DesktopArtifactRegistry::class, $registry);
        $first = $this->catalog->change($release['id'], 'pilot', $data, 7);
        $this->assertSame($first, $this->catalog->change($release['id'], 'pilot', $data, 7));
        $this->assertDatabaseCount('desktop_release_events', 3);
    }
}
