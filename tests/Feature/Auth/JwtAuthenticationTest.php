<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Tests\TestCase;

class JwtAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'jwt.secret' => str_repeat('test', 16),
            'jwt.algo' => 'HS256',
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_api_guard_issues_and_verifies_a_signed_token_with_the_configured_lifetime(): void
    {
        $user = User::create([
            'name' => 'jwt-test',
            'email' => 'jwt@example.test',
            'password' => 'test-password',
        ]);

        $token = Auth::guard('api')->login($user);
        Auth::forgetGuards();

        $guard = Auth::guard('api')->setToken($token);
        $authenticated = $guard->user();
        $payload = $guard->getPayload();

        $this->assertInstanceOf(User::class, $authenticated);
        $this->assertSame($user->id, $authenticated->id);
        $this->assertSame($user->id, (int) $payload->get('sub'));
        $this->assertSame(config('jwt.ttl') * 60, $payload->get('exp') - $payload->get('iat'));
    }

    public function test_api_guard_rejects_malformed_tokens(): void
    {
        $this->expectException(TokenInvalidException::class);

        Auth::guard('api')->setToken('invalid-token')->user();
    }
}
