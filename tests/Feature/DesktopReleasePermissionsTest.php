<?php

namespace Tests\Feature;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\EnsureDesktopReleasePermission;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use Database\Seeders\DesktopReleasePermissionSeeder;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DesktopReleasePermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['modulo_estados', 'vista_estados'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('descripcion');
            });
            DB::table($name)->insert([['id' => 1, 'descripcion' => 'ACTIVO'], ['id' => 2, 'descripcion' => 'INACTIVO']]);
        }
        Schema::create('modulos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->integer('estado');
        });
        Schema::create('vistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->string('nombre');
            $table->integer('modulo');
            $table->integer('estado');
            $table->timestamps();
        });
        Schema::create('actionsvistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->string('nombre');
            $table->integer('vista');
            $table->timestamps();
        });
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            $table->integer('usuario');
            $table->integer('modulo');
            $table->integer('vista');
            $table->integer('actionvista');
            $table->bigInteger('lifetime');
        });
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'cfg', 'estado' => 1]);
        (new DesktopReleasePermissionSeeder)->run();
        (require database_path('migrations/2026_10_26_154927_create_desktop_licensing_tables.php'))->up();
        (require database_path('migrations/2026_10_28_000001_create_desktop_release_catalog.php'))->up();
    }

    private function grant(string $action): void
    {
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1,
            'vista' => DB::table('vistas')->where('codigo', 'upd')->value('id'),
            'actionvista' => DB::table('actionsvistas')->where('codigo', $action)->value('id'),
            'lifetime' => now()->addYear()->timestamp]);
    }

    private function allows(string $action, string $header, ?int $user = 7): bool
    {
        $request = Request::create('/api/config/desktop-releases');
        $request->headers->set('Action-Btn', $header);
        $request->setUserResolver(fn () => $user === null ? null : new GenericUser(['id' => $user]));
        try {
            return (new EnsureDesktopReleasePermission)->handle($request, fn () => response('allowed'), $action)->getStatusCode() === 200;
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());

            return false;
        }
    }

    public function test_catalog_is_idempotent_without_automatic_grants(): void
    {
        $ids = DB::table('actionsvistas')->pluck('id')->all();
        (new DesktopReleasePermissionSeeder)->run();
        $this->assertSame($ids, DB::table('actionsvistas')->pluck('id')->all());
        $this->assertCount(8, $ids);
        $this->assertDatabaseCount('permisos', 0);
    }

    public function test_list_does_not_imply_any_mutation_or_audit_permission(): void
    {
        $this->grant('updbtn01');
        $this->assertTrue($this->allows('updbtn01', 'updbtn01'));
        foreach (range(2, 8) as $number) {
            $code = 'updbtn0'.$number;
            $this->assertFalse($this->allows($code, $code));
        }
        $this->assertFalse($this->allows('updbtn01', 'updbtn02'));
        $this->assertFalse($this->allows('updbtn01', 'updbtn01', null));
    }

    public function test_expired_assignment_or_inactive_view_is_denied(): void
    {
        $this->grant('updbtn06');
        DB::table('permisos')->update(['lifetime' => now()->subMinute()->timestamp]);
        $this->assertFalse($this->allows('updbtn06', 'updbtn06'));
        DB::table('permisos')->update(['lifetime' => now()->addMinute()->timestamp]);
        $this->assertTrue($this->allows('updbtn06', 'updbtn06'));
        DB::table('vistas')->update(['estado' => 2]);
        $this->assertFalse($this->allows('updbtn06', 'updbtn06'));
    }

    public function test_replayed_http_request_checks_current_permission_before_durable_response(): void
    {
        $this->grant('updbtn02');
        $this->actingAs(new GenericUser(['id' => 7]), 'api');
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class, Authenticate::class]);
        $artifact = (string) Str::uuid();
        DB::table('desktop_release_artifacts')->insert(['id' => $artifact, 'product' => 'shpos', 'version' => '1.0.1.0',
            'architecture' => 'x64', 'bucket' => 'synthetic-updates', 'object_id' => str_repeat('a', 64),
            'object_key' => 'synthetic/private.msix', 'sha256' => str_repeat('b', 64), 'size_bytes' => 100,
            'package_name' => '2f329250-d131-4a5a-bbdd-df443499ebcc', 'publisher' => 'CN=USER',
            'metadata' => '{}', 'verified_at' => now('UTC'), 'created_at' => now('UTC')]);
        $payload = ['request_id' => (string) Str::uuid(), 'artifact_id' => $artifact, 'notes' => 'Synthetic notes',
            'min_api_contract' => 1, 'min_schema_version' => 258, 'max_schema_version' => null, 'required' => false];
        $headers = ['Action-Btn' => 'updbtn02', 'Idempotency-Key' => 'same-synthetic-request'];
        $first = $this->postJson('/api/config/desktop-releases', $payload, $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/config/desktop-releases', $payload, $headers)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        DB::table('permisos')->delete();
        $this->postJson('/api/config/desktop-releases', $payload, $headers)->assertForbidden();
        $this->assertDatabaseCount('desktop_release_events', 1);
    }

    public function test_http_rejects_client_supplied_product_or_storage_reference(): void
    {
        $this->grant('updbtn02');
        $this->actingAs(new GenericUser(['id' => 7]), 'api');
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class, Authenticate::class]);
        $this->postJson('/api/config/desktop-releases', ['request_id' => (string) Str::uuid(),
            'artifact_id' => (string) Str::uuid(), 'notes' => 'Synthetic notes', 'min_api_contract' => 1,
            'min_schema_version' => 0, 'required' => false, 'product' => 'shpos', 'object_id' => str_repeat('b', 64)],
            ['Action-Btn' => 'updbtn02'])->assertUnprocessable()->assertJsonValidationErrors(['product', 'object_id']);
        $this->assertDatabaseCount('desktop_releases', 0);
    }
}
