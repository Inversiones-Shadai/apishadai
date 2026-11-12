<?php

namespace Tests\Feature;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\EnsureLicensePermission;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Models\User;
use App\Services\Licensing\LicenseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesktopLicenseCustomersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->freezeTime();
        (require database_path('migrations/2026_10_26_154927_create_desktop_licensing_tables.php'))->up();
        (require database_path('migrations/2026_10_26_162642_add_terms_to_desktop_licenses_table.php'))->up();
        (require database_path('migrations/2026_11_07_000001_add_desktop_license_key_reveal.php'))->up();
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, EnsureLicensePermission::class, IdempotencyMiddleware::class,
            JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class]);
        $user = new User;
        $user->id = 1;
        $this->actingAs($user, 'api');
    }

    public function test_migration_assigns_existing_licenses_to_dndev_preserving_keys_dates_and_device_bindings(): void
    {
        $id = (string) Str::uuid();
        $before = ['id' => $id, 'label' => 'Existing license', 'product' => 'shpos', 'key_hash' => hash('sha256', 'synthetic-key'),
            'key_suffix' => '12345678', 'seats' => 3, 'enabled' => 1, 'expires_at' => '2027-10-01 12:00:00',
            'created_by' => 1, 'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00'];
        DB::table('desktop_licenses')->insert($before);
        $activation = (string) Str::uuid();
        DB::table('desktop_license_devices')->insert(['id' => $activation, 'license_id' => $id, 'device_name' => 'TEST-PC',
            'device_hash' => str_repeat('a', 64), 'key_hash' => str_repeat('b', 64), 'public_key' => 'synthetic-public-key',
            'active_identity' => str_repeat('c', 64), 'last_validated_at' => now('UTC'), 'reserved_until' => now('UTC')->addHours(72),
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->migrateCustomers();
        $customer = DB::table('desktop_license_customers')->where('name', 'DNDEV')->first();
        $this->assertNotNull($customer);
        $this->assertDatabaseHas('desktop_licenses', $before + ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('desktop_license_devices', ['id' => $activation, 'license_id' => $id, 'public_key' => 'synthetic-public-key']);
        $this->assertSame(0, DB::table('desktop_licenses')->whereNull('customer_id')->count());
    }

    public function test_creates_customer_and_rejects_case_or_whitespace_duplicates(): void
    {
        $this->migrateCustomers();
        $this->postJson('/api/config/licenses/customers', ['name' => '  Cliente   Dos  '])->assertOk()->assertJsonPath('data.name', 'Cliente Dos');
        $this->assertDatabaseHas('desktop_license_customers', ['name' => 'Cliente Dos', 'name_key' => 'cliente dos']);
        $this->postJson('/api/config/licenses/customers', ['name' => '  cliente dos '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/config/licenses/customers', ['name' => 'dndev'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('desktop_license_customers', 2);
    }

    public function test_requires_a_customer_and_refuses_unknown_customer_when_generating(): void
    {
        $this->migrateCustomers();
        $payload = ['label' => 'Named license', 'product' => 'shpos', 'seats' => 1, 'term_months' => 1];
        $this->postJson('/api/config/licenses', $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/config/licenses', $payload + ['customer_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('desktop_licenses', 0);
        $this->postJson('/api/config/licenses', $payload + ['customer_id' => $this->dndev()])->assertUnprocessable()
            ->assertJsonValidationErrors(['challenge_id', 'code']);
        $this->assertDatabaseCount('desktop_licenses', 0);
    }

    public function test_lists_groups_with_customer_names_and_customer_filter_survives_pagination_and_search(): void
    {
        $this->migrateCustomers();
        $other = $this->customer('Other company');
        $this->issue($this->dndev(), 'DNDEV license');
        for ($i = 1; $i <= 23; $i++) {
            $this->issue($other, 'Other license '.$i);
        }
        $this->getJson('/api/config/licenses')->assertOk()->assertJsonPath('data.0.customer_name', 'DNDEV')->assertJsonPath('meta.total', 24);
        $second = $this->getJson('/api/config/licenses?customer_id='.$other.'&page=2')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 23);
        foreach ($second->json('data') as $license) {
            $this->assertSame($other, $license['customer_id']);
            $this->assertSame('Other company', $license['customer_name']);
        }
        $this->getJson('/api/config/licenses?customer_id='.$other.'&search=DNDEV')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/config/licenses?search=DNDEV')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/config/licenses?customer_id='.(string) Str::uuid())->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_customer_counts_are_complete_and_customer_catalog_is_searchable_and_paginated(): void
    {
        $this->migrateCustomers();
        $this->issue($this->dndev(), 'First');
        $this->issue($this->dndev(), 'Second');
        for ($i = 1; $i <= 12; $i++) {
            $this->customer('Synthetic company '.sprintf('%02d', $i));
        }
        $this->getJson('/api/config/licenses/customers?search=dndev')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'DNDEV')->assertJsonPath('data.0.license_count', 2);
        $this->getJson('/api/config/licenses/customers?page=2')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 13);
    }

    public function test_renaming_customer_preserves_ownership_and_rejects_duplicate_names(): void
    {
        $this->migrateCustomers();
        $license = $this->issue($this->dndev(), 'First');
        $other = $this->customer('Other');
        $this->putJson('/api/config/licenses/customers/'.$this->dndev(), ['name' => 'DNDEV Group'])->assertOk();
        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id'], 'customer_id' => $this->dndev()]);
        $this->getJson('/api/config/licenses?search=DNDEV')->assertOk()->assertJsonPath('data.0.customer_name', 'DNDEV Group');
        $this->putJson('/api/config/licenses/customers/'.$other, ['name' => 'dndev group'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('desktop_license_customers', ['id' => $other, 'name' => 'Other']);
    }

    public function test_reassigning_license_changes_only_customer_metadata_and_records_the_change(): void
    {
        $this->migrateCustomers();
        $license = $this->issue($this->dndev(), 'First');
        $before = DB::table('desktop_licenses')->where('id', $license['id'])->first();
        $other = $this->customer('Other');
        $this->putJson('/api/config/licenses/'.$license['id'], ['customer_id' => $other, 'label' => 'First', 'seats' => 1, 'enabled' => true])->assertOk();
        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id'], 'customer_id' => $other, 'key_hash' => $before->key_hash,
            'expires_at' => $before->expires_at, 'product' => 'shpos', 'key_suffix' => $before->key_suffix]);
        $this->assertDatabaseHas('desktop_license_events', ['license_id' => $license['id'], 'action' => 'customer_change', 'result' => $other]);
        $this->getJson('/api/config/licenses?customer_id='.$this->dndev())->assertOk()->assertJsonCount(0, 'data');
    }

    private function migrateCustomers(): void
    {
        (require database_path('migrations/2026_10_26_172148_add_customers_to_desktop_licenses.php'))->up();
    }

    private function dndev(): string
    {
        return DB::table('desktop_license_customers')->orderBy('created_at')->value('id');
    }

    private function customer(string $name): string
    {
        return $this->postJson('/api/config/licenses/customers', ['name' => $name])->assertOk()->json('data.id');
    }

    private function issue(string $customer, string $label): array
    {
        return app(LicenseManager::class)->issue(['customer_id' => $customer, 'label' => $label, 'product' => 'shpos', 'seats' => 1, 'term_months' => 1], 1);
    }
}
