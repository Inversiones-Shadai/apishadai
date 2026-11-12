<?php

namespace Tests\Feature;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\EnsureLicensePermission;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Mail\DesktopLicenseGenerationCodeMail;
use App\Models\User;
use App\Services\Licensing\LicenseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class DesktopLicenseGenerationApprovalTest extends TestCase
{
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['2026_10_26_154927_create_desktop_licensing_tables.php', '2026_10_26_162642_add_terms_to_desktop_licenses_table.php',
            '2026_10_26_172148_add_customers_to_desktop_licenses.php', '2026_11_07_000001_add_desktop_license_key_reveal.php',
            '2026_11_07_000002_create_desktop_license_generation_challenges.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('appconfig', function (Blueprint $table): void {
            $table->id();
            $table->string('mail_cc1')->nullable();
        });
        DB::table('appconfig')->insert(['mail_cc1' => 'administrator@example.test']);
        $this->payload = ['customer_id' => DB::table('desktop_license_customers')->value('id'), 'label' => 'Caja principal',
            'product' => 'shpos', 'seats' => 2, 'term_months' => 6];
        $user = new User;
        $user->id = 7;
        $this->actingAs($user, 'api')->withToken('session-one');
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, EnsureLicensePermission::class,
            JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class]);
        Mail::fake();
    }

    public function test_creation_requires_approval_even_when_called_directly(): void
    {
        $this->postJson('/api/config/licenses', $this->payload)->assertUnprocessable()->assertJsonValidationErrors(['challenge_id', 'code']);
        $this->postJson('/api/config/licenses', $this->payload + ['challenge_id' => (string) Str::uuid(), 'code' => '123456'])->assertUnprocessable();
        $this->assertDatabaseCount('desktop_licenses', 0);
        Mail::assertNothingSent();
    }

    public function test_request_sends_details_only_to_administrator_and_creates_nothing_until_valid_confirmation(): void
    {
        $challenge = $this->requestCode();
        $this->assertDatabaseCount('desktop_licenses', 0);
        $challenge->assertDontSee($this->sentCode())->assertDontSee('license_key');
        $this->assertNotSame($this->sentCode(), DB::table('desktop_license_generation_challenges')->value('code_hash'));
        Mail::assertSent(DesktopLicenseGenerationCodeMail::class, fn ($mail) => $mail->hasTo('administrator@example.test')
            && count($mail->to) === 1 && $mail->licenseLabel === 'Caja principal' && $mail->product === 'shpos'
            && $mail->seats === 2 && $mail->termMonths === 6 && str_contains($mail->render(), 'DNDEV'));
        $this->withHeader('Idempotency-Key', 'generation-test');
        $payload = $this->confirmation($challenge);
        $created = $this->postJson('/api/config/licenses', $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $key = $created->json('data.license_key');
        $this->assertStringStartsWith('SHD-', $key);
        $this->assertSame($key, Crypt::decryptString(DB::table('desktop_licenses')->value('key_encrypted')));
        $this->assertDatabaseHas('desktop_licenses', ['customer_id' => $this->payload['customer_id'], 'label' => 'Caja principal', 'seats' => 2, 'term_months' => 6]);
        $this->postJson('/api/config/licenses', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('desktop_licenses', 1);
        $this->assertDatabaseHas('desktop_license_events', ['license_id' => $created->json('data.id'), 'actor_id' => 7, 'action' => 'generate_approval', 'result' => 'authorized']);
        $this->assertStringNotContainsString($key, json_encode(DB::table('desktop_license_events')->get()));
        $this->postJson('/api/config/licenses/'.$created->json('data.id').'/reveal-key', [
            'challenge_id' => $payload['challenge_id'], 'code' => $payload['code'],
        ])->assertUnprocessable();
    }

    public function test_five_wrong_attempts_block_creation_and_are_not_rolled_back(): void
    {
        $payload = $this->confirmation($this->requestCode());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/config/licenses', array_replace($payload, ['code' => '000000']))->assertUnprocessable();
        }
        $this->assertDatabaseHas('desktop_license_generation_challenges', ['actor_id' => 7, 'attempts' => 5]);
        $this->postJson('/api/config/licenses', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('desktop_licenses', 0);
    }

    public function test_authorization_is_bound_to_all_license_fields_user_and_session(): void
    {
        $payload = $this->confirmation($this->requestCode());
        $otherCustomer = (string) Str::uuid();
        DB::table('desktop_license_customers')->insert(['id' => $otherCustomer, 'name' => 'Otro cliente', 'name_key' => 'otro cliente',
            'created_at' => now(), 'updated_at' => now()]);
        foreach (['customer_id' => $otherCustomer, 'label' => 'Otra caja', 'product' => 'hq-client', 'seats' => 3, 'term_months' => 12] as $field => $value) {
            $this->postJson('/api/config/licenses', array_replace($payload, [$field => $value]))->assertUnprocessable();
        }
        $this->withToken('session-two')->postJson('/api/config/licenses', $payload)->assertUnprocessable();
        $this->withToken('session-one');
        $otherUser = new User;
        $otherUser->id = 8;
        $this->actingAs($otherUser, 'api')->postJson('/api/config/licenses', $payload)->assertUnprocessable();
        $otherUser->id = 7;
        $this->actingAs($otherUser, 'api');
        $this->assertDatabaseCount('desktop_licenses', 0);
        $this->postJson('/api/config/licenses', $payload)->assertOk();
    }

    public function test_expired_code_cannot_create_a_license(): void
    {
        $payload = $this->confirmation($this->requestCode());
        $this->travel(10)->minutes();
        $this->postJson('/api/config/licenses', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('desktop_licenses', 0);
    }

    public function test_resends_have_cooldown_and_invalidate_the_previous_code(): void
    {
        $first = $this->confirmation($this->requestCode());
        $this->postJson('/api/config/licenses/generation-code', $this->payload)->assertStatus(429);
        Mail::assertSentCount(1);
        $this->travel(61)->seconds();
        $second = $this->confirmation($this->requestCode());
        $this->postJson('/api/config/licenses', $first)->assertUnprocessable();
        $this->postJson('/api/config/licenses', $second)->assertOk();
    }

    public function test_email_change_invalidates_authorization_and_invalid_email_prevents_sending(): void
    {
        $payload = $this->confirmation($this->requestCode());
        DB::table('appconfig')->update(['mail_cc1' => 'new-admin@example.test']);
        $this->postJson('/api/config/licenses', $payload)->assertUnprocessable();
        DB::table('appconfig')->update(['mail_cc1' => 'invalid']);
        $this->postJson('/api/config/licenses/generation-code', $this->payload)->assertStatus(503);
        $this->assertDatabaseCount('desktop_licenses', 0);
        Mail::assertSentCount(1);
    }

    public function test_smtp_failure_leaves_no_valid_challenge_or_license(): void
    {
        Mail::shouldReceive('mailer')->with('smtp')->once()->andThrow(new RuntimeException('SMTP failed'));
        $this->postJson('/api/config/licenses/generation-code', $this->payload)->assertStatus(503);
        $this->assertDatabaseCount('desktop_license_generation_challenges', 0);
        $this->assertDatabaseCount('desktop_licenses', 0);
    }

    public function test_failed_issue_rolls_back_consumption_and_successful_retry_creates_only_one_license(): void
    {
        $payload = $this->confirmation($this->requestCode());
        $calls = 0;
        $this->mock(LicenseManager::class)->shouldReceive('issue')->twice()->andReturnUsing(function ($data, $actor) use (&$calls) {
            if ($calls++ === 0) {
                throw new RuntimeException('Issue failed');
            }

            return (new LicenseManager)->issue($data, $actor);
        });
        $this->postJson('/api/config/licenses', $payload)->assertStatus(500);
        $this->assertDatabaseCount('desktop_licenses', 0);
        $this->assertDatabaseHas('desktop_license_generation_challenges', ['actor_id' => 7, 'attempts' => 0, 'consumed_at' => null]);
        $this->postJson('/api/config/licenses', $payload)->assertOk();
        $this->assertDatabaseCount('desktop_licenses', 1);
    }

    public function test_endpoints_require_generate_permission_and_never_store_idempotency_responses(): void
    {
        foreach (['/api/config/licenses', '/api/config/licenses/generation-code'] as $path) {
            $route = app('router')->getRoutes()->match(Request::create($path, 'POST'));
            $this->assertContains(EnsureLicensePermission::class.':licbtn02', $route->gatherMiddleware());
            $this->assertContains(IdempotencyMiddleware::class, $route->excludedMiddleware());
        }
        $this->postJson('/api/config/licenses/generation-code', array_replace($this->payload, ['term_months' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('term_months');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('desktop_license_generation_challenges', 0);
    }

    private function requestCode(): TestResponse
    {
        return $this->postJson('/api/config/licenses/generation-code', $this->payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    private function sentCode(): string
    {
        return Mail::sent(DesktopLicenseGenerationCodeMail::class)->last()->code;
    }

    private function confirmation(TestResponse $response): array
    {
        return $this->payload + ['challenge_id' => $response->json('data.challenge_id'), 'code' => $this->sentCode()];
    }
}
