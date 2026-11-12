<?php

namespace Tests\Feature;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\EnsureLicensePermission;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Mail\DesktopLicenseKeyCodeMail;
use App\Models\User;
use App\Services\Licensing\LicenseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DesktopLicenseKeyRevealTest extends TestCase
{
    private array $license;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['2026_10_26_154927_create_desktop_licensing_tables.php', '2026_10_26_162642_add_terms_to_desktop_licenses_table.php',
            '2026_10_26_172148_add_customers_to_desktop_licenses.php', '2026_11_07_000001_add_desktop_license_key_reveal.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('appconfig', function (Blueprint $table): void {
            $table->id();
            $table->string('mail_cc1')->nullable();
        });
        DB::table('appconfig')->insert(['mail_cc1' => 'administrator@example.test']);
        $this->license = app(LicenseManager::class)->issue([
            'customer_id' => DB::table('desktop_license_customers')->value('id'), 'label' => 'Caja principal',
            'product' => 'shpos', 'term_months' => 1, 'seats' => 1,
        ], 7);
        $user = new User;
        $user->id = 7;
        $this->actingAs($user, 'api')->withToken('session-one');
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, EnsureLicensePermission::class,
            JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class]);
        Mail::fake();
    }

    public function test_stores_only_encrypted_key_and_never_returns_it_in_lists_or_details(): void
    {
        $stored = DB::table('desktop_licenses')->first();
        $this->assertNotSame($this->license['license_key'], $stored->key_encrypted);
        $this->assertSame($this->license['license_key'], Crypt::decryptString($stored->key_encrypted));
        $list = $this->getJson('/api/config/licenses')->assertOk()->assertJsonPath('data.0.can_reveal_key', true);
        $details = $this->getJson($this->url())->assertOk();
        foreach ([$list, $details] as $response) {
            $response->assertDontSee($this->license['license_key'])->assertDontSee('key_encrypted')->assertDontSee('key_hash');
        }
    }

    public function test_sends_code_only_to_configured_administrator_and_reveals_once(): void
    {
        $challenge = $this->requestCode();
        $code = $this->sentCode();
        $this->assertNotSame($code, DB::table('desktop_license_key_challenges')->value('code_hash'));
        $challenge->assertDontSee($code)->assertDontSee($this->license['license_key']);
        Mail::assertSent(DesktopLicenseKeyCodeMail::class, fn ($mail) => $mail->hasTo('administrator@example.test') && count($mail->to) === 1
            && ! str_contains($mail->render(), $this->license['license_key']));
        $payload = ['challenge_id' => $challenge->json('data.challenge_id'), 'code' => $code];
        $this->postJson($this->url('/reveal-key'), $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.license_key', $this->license['license_key']);
        $this->postJson($this->url('/reveal-key'), $payload)->assertUnprocessable()->assertDontSee($this->license['license_key']);
        $this->assertDatabaseHas('desktop_license_events', ['license_id' => $this->license['id'], 'actor_id' => 7, 'action' => 'key_reveal', 'result' => 'authorized']);
        $this->assertStringNotContainsString($this->license['license_key'], json_encode(DB::table('desktop_license_events')->get()));
    }

    public function test_five_failed_attempts_remain_committed_and_block_the_correct_code(): void
    {
        $challenge = $this->requestCode()->json('data.challenge_id');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($this->url('/reveal-key'), ['challenge_id' => $challenge, 'code' => '000000'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('desktop_license_key_challenges', ['id' => $challenge, 'attempts' => 5]);
        $this->postJson($this->url('/reveal-key'), ['challenge_id' => $challenge, 'code' => $this->sentCode()])->assertUnprocessable();
    }

    public function test_expired_code_is_rejected(): void
    {
        $challenge = $this->requestCode()->json('data.challenge_id');
        $this->travel(10)->minutes();
        $this->postJson($this->url('/reveal-key'), ['challenge_id' => $challenge, 'code' => $this->sentCode()])->assertUnprocessable();
    }

    public function test_code_cannot_be_used_by_another_user_session_or_license(): void
    {
        $challenge = $this->requestCode()->json('data.challenge_id');
        $payload = ['challenge_id' => $challenge, 'code' => $this->sentCode()];
        $this->withToken('session-two')->postJson($this->url('/reveal-key'), $payload)->assertUnprocessable();
        $this->withToken('session-one');
        $user = new User;
        $user->id = 8;
        $this->actingAs($user, 'api')->postJson($this->url('/reveal-key'), $payload)->assertUnprocessable();
        $user->id = 7;
        $other = app(LicenseManager::class)->issue([
            'customer_id' => $this->license['customer_id'], 'label' => 'Otra caja', 'product' => 'shpos', 'term_months' => 1, 'seats' => 1,
        ], 7);
        $this->actingAs($user, 'api')->postJson('/api/config/licenses/'.$other['id'].'/reveal-key', $payload)->assertUnprocessable();
        $this->postJson($this->url('/reveal-key'), $payload)->assertOk();
    }

    public function test_resend_has_cooldown_and_invalidates_previous_challenge(): void
    {
        $first = $this->requestCode()->json('data.challenge_id');
        $firstCode = $this->sentCode();
        $this->postJson($this->url('/key-code'))->assertStatus(429);
        Mail::assertSentCount(1);
        $this->travel(61)->seconds();
        $second = $this->requestCode()->json('data.challenge_id');
        $this->assertNotSame($first, $second);
        $this->postJson($this->url('/reveal-key'), ['challenge_id' => $first, 'code' => $firstCode])->assertUnprocessable();
        $this->postJson($this->url('/reveal-key'), ['challenge_id' => $second, 'code' => $this->sentCode()])->assertOk();
    }

    public function test_administrator_email_change_invalidates_code_and_invalid_email_prevents_sending(): void
    {
        $challenge = $this->requestCode()->json('data.challenge_id');
        DB::table('appconfig')->update(['mail_cc1' => 'new-admin@example.test']);
        $this->postJson($this->url('/reveal-key'), ['challenge_id' => $challenge, 'code' => $this->sentCode()])->assertUnprocessable();
        DB::table('appconfig')->update(['mail_cc1' => 'invalid']);
        $this->postJson($this->url('/key-code'))->assertStatus(503);
        Mail::assertSentCount(1);
    }

    public function test_legacy_keys_are_not_recoverable_and_smtp_failure_does_not_leave_a_valid_challenge(): void
    {
        DB::table('desktop_licenses')->update(['key_encrypted' => null]);
        $this->getJson('/api/config/licenses')->assertOk()->assertJsonPath('data.0.can_reveal_key', false);
        $this->postJson($this->url('/key-code'))->assertUnprocessable();
        Mail::assertNothingSent();
        DB::table('desktop_licenses')->update(['key_encrypted' => Crypt::encryptString($this->license['license_key'])]);
        Mail::shouldReceive('mailer')->with('smtp')->once()->andThrow(new \RuntimeException('SMTP failed'));
        $this->postJson($this->url('/key-code'))->assertStatus(503);
        $this->assertDatabaseCount('desktop_license_key_challenges', 0);
    }

    public function test_routes_require_detail_permission_and_bypass_idempotency_response_storage(): void
    {
        foreach (['/key-code', '/reveal-key'] as $path) {
            $route = app('router')->getRoutes()->match(Request::create($this->url($path), 'POST'));
            $this->assertContains(EnsureLicensePermission::class.':licbtn04', $route->gatherMiddleware());
            $this->assertContains(IdempotencyMiddleware::class, $route->excludedMiddleware());
        }
        // An idempotency header must never cause the revealed key to be stored or replayed.
        $this->withHeader('Idempotency-Key', 'reveal-test');
        $challenge = $this->requestCode()->json('data.challenge_id');
        $payload = ['challenge_id' => $challenge, 'code' => $this->sentCode()];
        $this->postJson($this->url('/reveal-key'), $payload)->assertOk();
        $this->postJson($this->url('/reveal-key'), $payload)->assertUnprocessable();
    }

    private function url(string $suffix = ''): string
    {
        return '/api/config/licenses/'.$this->license['id'].$suffix;
    }

    private function requestCode(): TestResponse
    {
        return $this->postJson($this->url('/key-code'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    private function sentCode(): string
    {
        return Mail::sent(DesktopLicenseKeyCodeMail::class)->last()->code;
    }
}
