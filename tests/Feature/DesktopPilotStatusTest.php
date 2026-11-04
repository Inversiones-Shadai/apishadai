<?php

namespace Tests\Feature;

use App\Services\DesktopUpdates\DesktopPilotStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesktopPilotStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 15:00:00', 'UTC'));
        foreach (['2026_10_26_154927_create_desktop_licensing_tables.php', '2026_10_28_000001_create_desktop_release_catalog.php',
            '2026_10_29_000001_create_desktop_update_events.php', '2026_10_29_000002_create_desktop_update_installation_reports.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function release(string $product = 'shpos', string $architecture = 'x64'): string
    {
        $id = (string) Str::uuid();
        $artifact = (string) Str::uuid();
        DB::table('desktop_release_artifacts')->insert(['id' => $artifact, 'product' => $product, 'version' => '1.0.5.0',
            'architecture' => $architecture, 'bucket' => 'secret-bucket', 'object_id' => bin2hex(random_bytes(32)), 'object_key' => 'private-package.msix',
            'sha256' => str_repeat('a', 64), 'size_bytes' => 42, 'package_name' => 'synthetic.package', 'publisher' => 'CN=USER',
            'metadata' => '{"private":"must-not-leak"}', 'verified_at' => now('UTC'), 'created_at' => now('UTC')]);
        DB::table('desktop_releases')->insert(['id' => $id, 'artifact_id' => $artifact, 'product' => $product, 'version' => '1.0.5.0',
            'version_sort' => '00001.00000.00005.00000', 'architecture' => $architecture, 'status' => 'pilot', 'revision' => 5,
            'notes' => 'Synthetic', 'min_api_contract' => 1, 'min_schema_version' => 259, 'required' => false,
            'created_by' => 7, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('desktop_release_tracks')->where('product', $product)->where('architecture', $architecture)->update(['pilot_release_id' => $id]);

        return $id;
    }

    private function device(string $release): string
    {
        $license = (string) Str::uuid();
        $id = (string) Str::uuid();
        DB::table('desktop_licenses')->insert(['id' => $license, 'label' => 'Private customer', 'product' => 'shpos',
            'key_hash' => hash('sha256', $license), 'key_suffix' => 'SECRET00', 'seats' => 1, 'enabled' => true,
            'expires_at' => now('UTC')->addMonth(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('desktop_license_devices')->insert(['id' => $id, 'license_id' => $license, 'device_name' => 'Private device name',
            'device_hash' => str_repeat('c', 64), 'key_hash' => hash('sha256', $id), 'public_key' => 'PRIVATE-FIXTURE-NOT-KEY',
            'active_identity' => hash('sha256', $id), 'last_validated_at' => now('UTC'), 'reserved_until' => now('UTC')->addDay(),
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('desktop_release_pilot_devices')->insert(['release_id' => $release, 'activation_id' => $id]);

        return $id;
    }

    private function attempt(string $release, string $device, int $minutesAgo = 10, string $action = 'updates.apply', string $status = 'authorized'): string
    {
        $id = (string) Str::uuid();
        DB::table('desktop_update_events')->insert(['authorization_id' => $id, 'request_nonce' => (string) Str::uuid(),
            'activation_id' => $device, 'action' => $action, 'status' => $status, 'reason' => '', 'product' => 'shpos',
            'architecture' => 'x64', 'current_version' => '1.0.4.0', 'api_contract' => 1, 'schema_version' => 259,
            'release_id' => $release, 'release_revision' => 3, 'expires_at' => now('UTC')->subMinutes($minutesAgo)->addMinute(),
            'created_at' => now('UTC')->subMinutes($minutesAgo)]);

        return $id;
    }

    private function report(string $attempt, string $result = 'installed', string $version = '1.0.5.0'): void
    {
        $grant = DB::table('desktop_update_events')->where('authorization_id', $attempt)->first();
        DB::table('desktop_update_installation_reports')->insert(['authorization_id' => $attempt, 'activation_id' => $grant->activation_id,
            'release_id' => $grant->release_id, 'release_revision' => $grant->release_revision, 'product' => $grant->product,
            'result' => $result, 'reported_version' => $version, 'failure_code' => $result === 'failed' ? 'private-diagnostic' : '', 'created_at' => now('UTC')]);
    }

    private function pilotStatus(): array
    {
        return app(DesktopPilotStatus::class)->snapshot('shpos', includeDevices: true)['pilots'][0];
    }

    public function test_no_pilot_or_empty_pilot_never_means_ready(): void
    {
        $this->assertFalse($this->pilotStatus()['current_pilot']);
        $this->release();
        $this->assertTrue($this->pilotStatus()['current_pilot']);
        $this->assertFalse($this->pilotStatus()['pilot_results_complete']);
    }

    public function test_download_and_check_are_not_installation_attempts(): void
    {
        $release = $this->release();
        $device = $this->device($release);
        $this->attempt($release, $device, action: 'updates.download');
        $this->attempt($release, $device, action: 'updates.check');
        $this->attempt($release, $device, status: 'denied');
        $status = $this->pilotStatus();
        $this->assertSame(1, $status['summary']['not_started']);
        $this->assertFalse($status['pilot_results_complete']);
    }

    public function test_pending_and_overdue_are_separate_and_boundary_is_inclusive(): void
    {
        $release = $this->release();
        foreach ([10, 30, 31] as $minutes) {
            $this->attempt($release, $this->device($release), $minutes);
        }
        $status = $this->pilotStatus();
        $this->assertSame(1, $status['summary']['awaiting_report']);
        $this->assertSame(2, $status['summary']['overdue_report']);
    }

    public function test_report_matching_grant_revision_remains_valid_after_pilot_assignment_revision_changes(): void
    {
        $release = $this->release();
        $this->report($this->attempt($release, $this->device($release)));
        $status = $this->pilotStatus();
        $this->assertSame(5, $status['revision']);
        $this->assertTrue($status['pilot_results_complete']);
        $this->assertSame(1, $status['summary']['installed_reported']);
        $this->assertSame(600, $status['devices'][0]['result_received_after_seconds']);
    }

    public function test_delayed_old_report_cannot_hide_new_pending_attempt(): void
    {
        $release = $this->release();
        $device = $this->device($release);
        $old = $this->attempt($release, $device, 40);
        $this->attempt($release, $device, 10);
        $this->report($old);
        $this->assertSame(1, $this->pilotStatus()['summary']['awaiting_report']);
        $this->assertFalse($this->pilotStatus()['pilot_results_complete']);
    }

    public function test_retry_success_replaces_old_failure_but_history_is_unchanged(): void
    {
        $release = $this->release();
        $device = $this->device($release);
        $this->report($this->attempt($release, $device, 40), 'failed', '1.0.4.0');
        $this->assertSame(1, $this->pilotStatus()['summary']['failed_reported']);
        $this->report($this->attempt($release, $device));
        $this->assertTrue($this->pilotStatus()['pilot_results_complete']);
        $this->assertDatabaseCount('desktop_update_installation_reports', 2);
    }

    public function test_same_timestamp_attempts_do_not_use_random_uuid_as_time_order(): void
    {
        $release = $this->release();
        $device = $this->device($release);
        $this->report($this->attempt($release, $device));
        $this->attempt($release, $device);
        $this->assertSame(1, $this->pilotStatus()['summary']['ambiguous_attempts']);
        $this->assertFalse($this->pilotStatus()['pilot_results_complete']);
    }

    public static function invalidReports(): array
    {
        return [['reported_version', '1.0.4.0'], ['activation_id', '00000000-0000-4000-8000-000000000001'],
            ['release_id', '00000000-0000-4000-8000-000000000002'], ['release_revision', 99], ['product', 'hq-client'],
            ['result', 'unknown'], ['created_at', '2026-10-30 14:00:00']];
    }

    #[DataProvider('invalidReports')]
    public function test_inconsistent_report_never_counts_as_installed(string $field, mixed $value): void
    {
        $release = $this->release();
        $this->report($this->attempt($release, $this->device($release)));
        DB::table('desktop_update_installation_reports')->update([$field => $value]);
        $this->assertSame(1, $this->pilotStatus()['summary']['invalid_report']);
        $this->assertFalse($this->pilotStatus()['pilot_results_complete']);
    }

    public static function ineligible(): array
    {
        return [['disabled'], ['expired'], ['revoked'], ['wrong-product']];
    }

    #[DataProvider('ineligible')]
    public function test_license_is_rechecked_without_losing_historical_report(string $reason): void
    {
        $release = $this->release();
        $this->report($this->attempt($release, $this->device($release)));
        match ($reason) {
            'disabled' => DB::table('desktop_licenses')->update(['enabled' => false]),
            'expired' => DB::table('desktop_licenses')->update(['expires_at' => now('UTC')]),
            'revoked' => DB::table('desktop_license_devices')->update(['revoked_at' => now('UTC')]),
            'wrong-product' => DB::table('desktop_licenses')->update(['product' => 'hq-client']),
        };
        $this->assertSame(1, $this->pilotStatus()['summary']['ineligible']);
        $this->assertDatabaseCount('desktop_update_installation_reports', 1);
    }

    public function test_wrong_product_architecture_and_removed_assignment_are_excluded(): void
    {
        $release = $this->release();
        $device = $this->device($release);
        $this->report($this->attempt($release, $device));
        DB::table('desktop_update_events')->update(['product' => 'hq-client']);
        $this->assertSame(1, $this->pilotStatus()['summary']['not_started']);
        DB::table('desktop_update_events')->update(['product' => 'shpos', 'architecture' => 'arm64']);
        $this->assertSame(1, $this->pilotStatus()['summary']['not_started']);
        DB::table('desktop_release_pilot_devices')->delete();
        $this->assertSame(0, $this->pilotStatus()['assigned_devices']);
        $this->assertFalse($this->pilotStatus()['pilot_results_complete']);
    }

    public function test_withdrawn_pilot_does_not_fall_back_to_historical_release(): void
    {
        $release = $this->release();
        $this->report($this->attempt($release, $this->device($release)));
        DB::table('desktop_releases')->update(['status' => 'withdrawn']);
        $this->assertFalse($this->pilotStatus()['current_pilot']);
        $this->assertFalse($this->pilotStatus()['pilot_results_complete']);
    }

    public function test_default_output_has_no_device_or_storage_or_license_secrets_and_performs_only_reads(): void
    {
        $release = $this->release();
        $device = $this->device($release);
        $this->report($this->attempt($release, $device));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $output = app(DesktopPilotStatus::class)->snapshot();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query['query']);
        }
        $json = json_encode($output);
        foreach ([$device, 'secret-bucket', 'private-package', 'must-not-leak', 'SECRET00', 'PRIVATE-FIXTURE', 'Private customer', 'private-diagnostic'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertFalse($output['writes_performed']);
        $this->assertSame('requires_environment_validation_and_operator_decision', $output['production_readiness']);
        $this->assertCount(3, $output['pilots']);
    }

    public function test_console_outputs_json_and_rejects_invalid_filters_without_connection_details(): void
    {
        $this->artisan('desktop-updates:pilot-status', ['--product' => 'shpos'])->assertSuccessful();
        foreach ([['--product' => 'unknown'], ['--architecture' => 'any'], ['--pending-minutes' => '1.5'], ['--pending-minutes' => '0'], ['--pending-minutes' => '10081']] as $options) {
            $this->artisan('desktop-updates:pilot-status', $options)->assertFailed();
        }
    }
}
