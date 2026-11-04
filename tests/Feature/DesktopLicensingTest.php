<?php

namespace Tests\Feature;

use App\Http\Controllers\API\Licensing\LicenseAdminController;
use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\EnsureLicensePermission;
use App\Http\Middleware\HeadquartersPosSignatureMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Services\Licensing\LicenseAuthority;
use App\Services\Licensing\LicenseManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DesktopLicensingTest extends TestCase
{
    private static string $privateKey = '';

    private static string $publicKey;

    private static string $keyPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$privateKey);
        self::$publicKey = openssl_pkey_get_details($key)['key'];
        self::$keyPath = tempnam(sys_get_temp_dir(), 'license-test-');
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
            'licensing.private_key_path' => self::$keyPath]);
        DB::purge('sqlite');
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC'));
        (require database_path('migrations/2026_10_26_154927_create_desktop_licensing_tables.php'))->up();
        (require database_path('migrations/2026_10_26_162642_add_terms_to_desktop_licenses_table.php'))->up();
        (require database_path('migrations/2026_10_26_172148_add_customers_to_desktop_licenses.php'))->up();
    }

    public function test_activates_over_http_and_returns_device_bound_signed_72_hour_authorization(): void
    {
        $license = $this->issue();
        $envelope = $this->proof(['license_key' => $license['license_key']]);
        $response = $this->withoutMiddleware([DeviceSecurityMiddleware::class, HeadquartersPosSignatureMiddleware::class])
            ->postJson('/api/headquarters/licenses/activate', $envelope)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $signed = $response->json('data');
        $this->assertSame(1, openssl_verify($signed['payload'], base64_decode($signed['signature']), self::$publicKey, OPENSSL_ALGO_SHA256));
        $claims = json_decode(base64_decode($signed['payload']), true);
        $this->assertSame('valid', $claims['status']);
        $this->assertSame('shpos', $claims['product']);
        $this->assertSame(str_repeat('a', 64), $claims['device_hash']);
        $this->assertSame(1793016000, $claims['issued_at']);
        $this->assertSame(1793275200, $claims['offline_until']);
        $this->assertDatabaseCount('desktop_license_devices', 1);
        $this->assertDatabaseHas('desktop_license_events', ['action' => 'activate', 'result' => 'valid']);
    }

    public function test_repeated_activation_does_not_consume_another_seat_and_validation_renews(): void
    {
        $license = $this->issue();
        $first = $this->exchange(['license_key' => $license['license_key']]);
        $this->assertSame($first['activation_id'], $this->exchange(['license_key' => $license['license_key']])['activation_id']);
        $this->travel(2)->days();
        $renewed = $this->exchange(['action' => 'validate', 'activation_id' => $first['activation_id']]);
        $this->assertSame('valid', $renewed['status']);
        $this->assertSame(1793448000, $renewed['offline_until']);
        $this->assertDatabaseCount('desktop_license_devices', 1);
    }

    public function test_rejects_a_second_device_when_seats_are_exhausted(): void
    {
        $license = $this->issue();
        $this->exchange(['license_key' => $license['license_key']]);
        $other = $this->otherKey();
        $result = $this->exchange(['license_key' => $license['license_key'], 'device_hash' => str_repeat('b', 64)], $other);
        $this->assertSame('seats_exhausted', $result['reason']);
        $this->assertDatabaseCount('desktop_license_devices', 1);
    }

    public function test_revocation_denies_refresh_and_reserves_seat_until_the_offline_lease_expires(): void
    {
        $license = $this->issue();
        $first = $this->exchange(['license_key' => $license['license_key']]);
        app(LicenseManager::class)->unlink($license['id'], $first['activation_id'], 1);
        $this->assertSame('device_revoked_or_mismatch', $this->exchange(['action' => 'validate', 'activation_id' => $first['activation_id']])['reason']);
        $other = $this->otherKey();
        $this->assertSame('seats_exhausted', $this->exchange(['license_key' => $license['license_key'], 'device_hash' => str_repeat('b', 64)], $other)['reason']);
        $this->travel(72)->hours();
        $this->assertSame('valid', $this->exchange(['license_key' => $license['license_key'], 'device_hash' => str_repeat('b', 64)], $other)['status']);
        $this->assertDatabaseHas('desktop_license_events', ['action' => 'unlink', 'result' => 'revoked']);
    }

    public function test_disabled_expired_wrong_product_and_wrong_key_are_denied_without_allocating(): void
    {
        $license = $this->issue();
        $this->assertSame('license_invalid', $this->exchange(['license_key' => $license['license_key'], 'product' => 'hq-client'])['reason']);
        $this->assertSame('license_invalid', $this->exchange(['license_key' => 'SHD-invalid'])['reason']);
        DB::table('desktop_licenses')->where('id', $license['id'])->update(['enabled' => false]);
        $this->assertSame('license_disabled', $this->exchange(['license_key' => $license['license_key']])['reason']);
        DB::table('desktop_licenses')->where('id', $license['id'])->update(['enabled' => true, 'expires_at' => now('UTC')->subSecond()]);
        $this->assertSame('license_expired', $this->exchange(['license_key' => $license['license_key']])['reason']);
        $this->assertDatabaseCount('desktop_license_devices', 0);
    }

    public function test_deletes_unlinked_license_immediately_preserving_audit_and_rejecting_its_key(): void
    {
        $license = $this->issue();
        $other = $this->issue();
        $activation = $this->exchange(['license_key' => $license['license_key']]);
        app(LicenseManager::class)->unlink($license['id'], $activation['activation_id'], 1);
        $this->assertDatabaseHas('desktop_license_devices', ['id' => $activation['activation_id'],
            'reserved_until' => now('UTC')->addHours(72)->format('Y-m-d H:i:s')]);

        $this->deleteLicense($license['id'])->assertOk()->assertJsonPath('message', 'Licencia eliminada.');

        $this->assertDatabaseMissing('desktop_licenses', ['id' => $license['id']]);
        $this->assertDatabaseMissing('desktop_license_devices', ['license_id' => $license['id']]);
        $this->assertDatabaseHas('desktop_licenses', ['id' => $other['id']]);
        $this->assertDatabaseHas('desktop_license_events', ['license_id' => $license['id'], 'action' => 'unlink']);
        $this->assertDatabaseHas('desktop_license_events', ['license_id' => $license['id'], 'action' => 'delete', 'actor_id' => 1]);
        $this->assertSame('license_invalid', $this->exchange(['license_key' => $license['license_key']])['reason']);
        $this->assertSame('license_invalid', $this->exchange(['action' => 'validate', 'activation_id' => $activation['activation_id']])['reason']);
        $this->deleteLicense($license['id'])->assertNotFound();
    }

    public function test_deletion_refuses_linked_devices_even_for_disabled_expired_licenses(): void
    {
        $license = $this->issue();
        $activation = $this->exchange(['license_key' => $license['license_key']]);
        DB::table('desktop_licenses')->where('id', $license['id'])->update(['enabled' => false, 'expires_at' => now('UTC')->subSecond()]);
        $this->travel(73)->hours();

        $this->deleteLicense($license['id'])->assertUnprocessable()
            ->assertJsonPath('message', 'Desvincula todos los equipos antes de eliminar la licencia.');

        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id']]);
        $this->assertDatabaseHas('desktop_license_devices', ['id' => $activation['activation_id'], 'revoked_at' => null]);
        $this->assertDatabaseMissing('desktop_license_events', ['action' => 'delete']);
    }

    public function test_deletes_never_activated_license_and_returns_not_found_for_unknown_id(): void
    {
        $license = $this->issue();
        $this->deleteLicense($license['id'])->assertOk();
        $this->assertDatabaseMissing('desktop_licenses', ['id' => $license['id']]);
        $this->deleteLicense((string) Str::uuid())->assertNotFound();
    }

    public function test_list_exposes_deletion_only_when_all_devices_are_unlinked_regardless_of_reserved_seats(): void
    {
        $license = $this->issue();
        $request = Request::create('/api/config/licenses');
        $canDelete = fn () => (new LicenseAdminController)->index($request)->getData(true)['data'][0]['can_delete'];
        $this->assertTrue($canDelete());
        $activation = $this->exchange(['license_key' => $license['license_key']]);
        $this->assertFalse($canDelete());
        app(LicenseManager::class)->unlink($license['id'], $activation['activation_id'], 1);
        $this->assertTrue($canDelete());
    }

    public function test_lease_is_capped_at_license_expiration(): void
    {
        $license = $this->issue();
        DB::table('desktop_licenses')->where('id', $license['id'])->update(['expires_at' => now('UTC')->addHour()]);
        $this->assertSame(1793019600, $this->exchange(['license_key' => $license['license_key']])['offline_until']);
    }

    public function test_cannot_validate_another_devices_activation_or_reactivate_revoked_key(): void
    {
        $license = $this->issue();
        $first = $this->exchange(['license_key' => $license['license_key']]);
        $other = $this->otherKey();
        $this->assertSame('device_revoked_or_mismatch', $this->exchange(['action' => 'validate', 'activation_id' => $first['activation_id']], $other)['reason']);
        app(LicenseManager::class)->unlink($license['id'], $first['activation_id'], 1);
        $this->assertSame('device_revoked_or_mismatch', $this->exchange(['license_key' => $license['license_key']])['reason']);
    }

    public function test_same_device_cannot_be_bound_to_two_licenses_for_the_same_product(): void
    {
        $a = $this->issue();
        $b = $this->issue();
        $this->exchange(['license_key' => $a['license_key']]);
        $this->assertSame('device_already_linked', $this->exchange(['license_key' => $b['license_key']])['reason']);
        $this->assertDatabaseCount('desktop_license_devices', 1);
    }

    public function test_rejects_replayed_nonce(): void
    {
        $license = $this->issue();
        $authority = app(LicenseAuthority::class);
        $proof = $authority->readProof($this->proof(['license_key' => $license['license_key']]), 'activate');
        app(LicenseManager::class)->exchange($proof, $authority);
        try {
            app(LicenseManager::class)->exchange($proof, $authority);
            $this->fail('Replay accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('desktop_license_devices', 1);
    }

    public function test_tampered_proof_and_stale_clock_are_rejected_over_http(): void
    {
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, HeadquartersPosSignatureMiddleware::class]);
        $bad = $this->proof(['license_key' => 'SHD-invalid']);
        $bad['signature'] = base64_encode(str_repeat('x', 384));
        $this->postJson('/api/headquarters/licenses/activate', $bad)->assertForbidden();
        $this->postJson('/api/headquarters/licenses/activate', $this->proof(['license_key' => 'SHD-invalid', 'timestamp' => now('UTC')->subMinutes(6)->timestamp]))->assertUnprocessable();
        $this->assertDatabaseCount('desktop_license_devices', 0);
    }

    public function test_missing_signing_key_does_not_allocate_a_seat(): void
    {
        $license = $this->issue();
        config(['licensing.private_key_path' => self::$keyPath.'.missing']);
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, HeadquartersPosSignatureMiddleware::class])
            ->postJson('/api/headquarters/licenses/activate', $this->proof(['license_key' => $license['license_key']]))->assertServiceUnavailable();
        $this->assertDatabaseCount('desktop_license_devices', 0);
    }

    public function test_only_key_hash_and_suffix_are_stored_and_seats_cannot_be_reduced_below_reservations(): void
    {
        $license = $this->issue();
        $this->assertDatabaseHas('desktop_licenses', ['key_hash' => hash('sha256', $license['license_key']), 'key_suffix' => substr($license['license_key'], -8)]);
        $this->exchange(['license_key' => $license['license_key']]);
        $this->expectException(HttpException::class);
        app(LicenseManager::class)->update($license['id'], ['customer_id' => $this->customerId(), 'label' => 'Synthetic', 'seats' => 0, 'enabled' => true], 1);
    }

    #[DataProvider('calendarTerms')]
    public function test_named_licenses_use_calendar_terms_for_one_application(string $start, int $months, string $end): void
    {
        $this->travelTo(CarbonImmutable::parse($start));
        $result = app(LicenseManager::class)->issue(['customer_id' => $this->customerId(), 'label' => '  Caja principal  ', 'product' => 'shpos', 'seats' => 1, 'term_months' => $months], 1);
        $this->assertSame($end, $result['expires_at']);
        $this->assertSame('Caja principal', $result['label']);
        $this->assertDatabaseHas('desktop_licenses', ['id' => $result['id'], 'label' => 'Caja principal', 'product' => 'shpos',
            'term_months' => $months, 'expires_at' => CarbonImmutable::parse($end)->format('Y-m-d H:i:s')]);
    }

    public static function calendarTerms(): array
    {
        return [
            ['2026-10-26T12:00:00Z', 1, '2026-11-26T12:00:00+00:00'],
            ['2026-10-26T12:00:00Z', 6, '2027-04-26T12:00:00+00:00'],
            ['2026-10-26T12:00:00Z', 12, '2027-10-26T12:00:00+00:00'],
            ['2026-01-31T18:00:00Z', 1, '2026-02-28T18:00:00+00:00'],
            ['2026-08-31T18:00:00Z', 6, '2027-02-28T18:00:00+00:00'],
            ['2024-02-29T18:00:00Z', 12, '2025-02-28T18:00:00+00:00'],
            ['2026-03-01T03:00:00Z', 1, '2026-03-29T03:00:00+00:00'],
        ];
    }

    public function test_renaming_and_suspending_do_not_extend_the_expiration_or_change_application(): void
    {
        $license = $this->issue();
        app(LicenseManager::class)->update($license['id'], ['customer_id' => $this->customerId(), 'label' => 'New name', 'seats' => 1, 'enabled' => false], 1);
        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id'], 'label' => 'New name', 'product' => 'shpos',
            'enabled' => false, 'expires_at' => '2027-10-26 12:00:00', 'term_months' => 12]);
        $this->assertDatabaseMissing('desktop_license_events', ['action' => 'renew']);
    }

    public function test_renewal_keeps_remaining_time_and_records_the_selected_term(): void
    {
        $license = $this->issue();
        $this->travel(10)->days();
        app(LicenseManager::class)->update($license['id'], ['customer_id' => $this->customerId(), 'label' => 'Renewed', 'seats' => 1, 'enabled' => true, 'renewal_months' => 6, 'expected_expires_at' => $license['expires_at']], 1);
        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id'], 'term_started_at' => '2027-10-26 12:00:00',
            'expires_at' => '2028-04-26 12:00:00', 'term_months' => 6, 'product' => 'shpos']);
        $this->assertDatabaseHas('desktop_license_events', ['license_id' => $license['id'], 'action' => 'renew', 'result' => '6_months']);
    }

    public function test_expired_license_renews_from_current_time(): void
    {
        $license = $this->issue();
        $this->travelTo(CarbonImmutable::parse('2028-01-31T18:00:00Z'));
        app(LicenseManager::class)->update($license['id'], ['customer_id' => $this->customerId(), 'label' => 'Renewed', 'seats' => 1, 'enabled' => true, 'renewal_months' => 1, 'expected_expires_at' => $license['expires_at']], 1);
        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id'], 'term_started_at' => '2028-01-31 18:00:00',
            'expires_at' => '2028-02-29 18:00:00', 'term_months' => 1]);
    }

    public function test_api_rejects_unsupported_terms_and_arbitrary_expiration(): void
    {
        Route::post('/api/config/licenses/term-test', function (Request $request) {
            $request->setUserResolver(fn () => new GenericUser(['id' => 1]));

            return (new LicenseAdminController)->store($request, app(LicenseManager::class));
        });
        $payload = ['customer_id' => $this->customerId(), 'label' => 'Named license', 'product' => 'shpos', 'seats' => 1];
        foreach ([0, 2, 3, 18, -1, 1.5] as $months) {
            $this->postJson('/api/config/licenses/term-test', $payload + ['term_months' => $months])->assertUnprocessable()->assertJsonValidationErrors('term_months');
        }
        $this->postJson('/api/config/licenses/term-test', $payload)->assertUnprocessable()->assertJsonValidationErrors('term_months');
        $this->postJson('/api/config/licenses/term-test', $payload + ['term_months' => 1, 'expires_at' => '2099-01-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $this->assertDatabaseCount('desktop_licenses', 0);
        $this->postJson('/api/config/licenses/term-test', $payload + ['term_months' => 6])->assertOk()->assertJsonPath('data.label', 'Named license')
            ->assertJsonPath('data.term_months', 6)->assertJsonPath('data.expires_at', '2027-04-26T12:00:00+00:00');
    }

    public function test_api_refuses_application_reassignment_and_unrestricted_renewal(): void
    {
        Route::put('/api/config/licenses/term-test/{id}', function (Request $request, string $id) {
            $request->setUserResolver(fn () => new GenericUser(['id' => 1]));

            return (new LicenseAdminController)->update($request, $id, app(LicenseManager::class));
        });
        $license = $this->issue();
        $payload = ['customer_id' => $this->customerId(), 'label' => 'Updated', 'seats' => 1, 'enabled' => true];
        foreach (['product' => 'hq-client', 'term_months' => 6, 'expires_at' => '2099-01-01', 'renewal_months' => 2] as $key => $value) {
            $this->putJson('/api/config/licenses/term-test/'.$license['id'], $payload + [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id'], 'product' => 'shpos', 'expires_at' => '2027-10-26 12:00:00']);
    }

    public function test_duplicate_or_stale_renewal_cannot_extend_the_period_twice(): void
    {
        $license = $this->issue();
        $data = ['customer_id' => $this->customerId(), 'label' => 'Renewed', 'seats' => 1, 'enabled' => true, 'renewal_months' => 6, 'expected_expires_at' => $license['expires_at']];
        app(LicenseManager::class)->update($license['id'], $data, 1);
        try {
            app(LicenseManager::class)->update($license['id'], $data, 1);
            $this->fail('Duplicate renewal accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseHas('desktop_licenses', ['id' => $license['id'], 'expires_at' => '2028-04-26 12:00:00']);
        $this->assertSame(1, DB::table('desktop_license_events')->where('action', 'renew')->count());
    }

    #[DataProvider('differentApplications')]
    public function test_a_named_license_cannot_be_used_in_another_desktop_application(string $licensed, string $attempted): void
    {
        $license = app(LicenseManager::class)->issue(['customer_id' => $this->customerId(), 'label' => 'Exclusive app license', 'product' => $licensed, 'seats' => 1, 'term_months' => 1], 1);
        $this->assertSame('valid', $this->exchange(['license_key' => $license['license_key'], 'product' => $licensed])['status']);
        $this->assertSame('license_invalid', $this->exchange(['license_key' => $license['license_key'], 'product' => $attempted])['reason']);
        $this->assertDatabaseCount('desktop_license_devices', 1);
    }

    public static function differentApplications(): array
    {
        return [['shpos', 'hq-client'], ['shpos', 'shpos-administrator'], ['hq-client', 'shpos'],
            ['hq-client', 'shpos-administrator'], ['shpos-administrator', 'shpos'], ['shpos-administrator', 'hq-client']];
    }

    private function deleteLicense(string $id): TestResponse
    {
        $this->withoutMiddleware([
            DeviceSecurityMiddleware::class,
            EnsureLicensePermission::class,
            IdempotencyMiddleware::class,
            JWTAuthenticationMiddleware::class,
            MatchTokenMiddleware::class,
        ]);
        $this->actingAs(new GenericUser(['id' => 1]), 'api');

        return $this->deleteJson('/api/config/licenses/'.$id);
    }

    private function customerId(): string
    {
        return DB::table('desktop_license_customers')->where('name_key', 'dndev')->value('id');
    }

    private function issue(): array
    {
        return app(LicenseManager::class)->issue(['customer_id' => $this->customerId(), 'label' => 'Synthetic test license', 'product' => 'shpos', 'seats' => 1, 'term_months' => 12], 1);
    }

    private function otherKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);

        return $private;
    }

    private function proof(array $overrides, ?string $private = null): array
    {
        $private ??= self::$privateKey;
        $public = openssl_pkey_get_details(openssl_pkey_get_private($private))['key'];
        $data = array_merge(['action' => 'activate', 'product' => 'shpos', 'device_hash' => str_repeat('a', 64),
            'device_name' => 'TEST-PC', 'public_key' => $public, 'timestamp' => now('UTC')->timestamp, 'nonce' => (string) Str::uuid()], $overrides);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        openssl_sign($json, $signature, $private, OPENSSL_ALGO_SHA256);

        return ['payload' => base64_encode($json), 'signature' => base64_encode($signature)];
    }

    private function exchange(array $data, ?string $private = null): array
    {
        $authority = app(LicenseAuthority::class);
        $envelope = app(LicenseManager::class)->exchange($authority->readProof($this->proof($data, $private), $data['action'] ?? 'activate'), $authority);

        return json_decode(base64_decode($envelope['payload']), true);
    }
}
