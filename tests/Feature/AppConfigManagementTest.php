<?php

namespace Tests\Feature;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppConfigManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, IdempotencyMiddleware::class, JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class]);
        $user = new User;
        $user->id = 1;
        $this->actingAs($user);

        Schema::create('appconfig', function (Blueprint $table): void {
            $table->id();
            $table->text('appv')->nullable();
            $table->string('mail_alert');
            $table->string('mail_cc1');
            $table->string('mail_cc2')->nullable();
            $table->string('mail_cc3')->nullable();
            $table->timestamps();
        });
        foreach (['modulo_estados', 'vista_estados'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('descripcion');
            });
            DB::table($name)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
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
            $table->timestamp('lifetime')->nullable();
        });
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'cfg', 'estado' => 1]);
        $this->migration()->up();
        foreach (DB::table('actionsvistas')->get() as $action) {
            DB::table('permisos')->insert(['usuario' => 1, 'modulo' => 1, 'vista' => $action->vista, 'actionvista' => $action->id]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_records_can_be_created_read_and_updated_without_changing_the_version_identifier(): void
    {
        $payload = $this->payload();
        $id = $this->withHeader('Action-Btn', 'acfbtn02')->postJson('/api/config/appconfig', $payload + ['id' => 99])
            ->assertCreated()->assertJsonPath('data.appv', $payload['appv'])->json('data.id');
        $this->assertNotEquals(99, $id);

        $this->withHeader('Action-Btn', 'acfbtn01')->getJson('/api/config/appconfig')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.mail_cc1', 'admin@example.test');
        $this->withHeader('Action-Btn', 'acfbtn03')->getJson('/api/config/appconfig/'.$id)
            ->assertOk()->assertJsonPath('data.mail_cc3', null);
        $this->putJson('/api/config/appconfig/'.$id, array_replace($payload, ['mail_cc1' => 'new@example.test', 'mail_cc2' => 'copy@example.test']))
            ->assertOk()->assertJsonPath('data.mail_cc1', 'new@example.test')->assertJsonPath('data.mail_cc2', 'copy@example.test');
        $this->getJson('/api/config/apputils/check?version='.$payload['appv'])->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseCount('appconfig', 1);
    }

    public function test_invalid_email_does_not_modify_configuration_and_missing_records_return_404(): void
    {
        DB::table('appconfig')->insert($this->payload());
        $this->withHeader('Action-Btn', 'acfbtn03')->putJson('/api/config/appconfig/1', array_replace($this->payload(), ['mail_cc1' => 'invalid']))
            ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['mail_cc1']]]);
        $this->assertDatabaseHas('appconfig', ['id' => 1, 'mail_cc1' => 'admin@example.test']);
        $this->getJson('/api/config/appconfig/999')->assertNotFound();
        $this->putJson('/api/config/appconfig/999', $this->payload())->assertNotFound();
    }

    public function test_permission_is_required_even_with_a_forged_action_header(): void
    {
        DB::table('permisos')->delete();
        $this->withHeader('Action-Btn', 'acfbtn01')->getJson('/api/config/appconfig')->assertForbidden();
        $this->withHeader('Action-Btn', 'acfbtn02')->postJson('/api/config/appconfig', $this->payload())->assertForbidden();
        $this->withHeader('Action-Btn', 'acfbtn03')->putJson('/api/config/appconfig/1', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('appconfig', 0);
    }

    public function test_expired_permissions_and_inactive_views_are_denied(): void
    {
        DB::table('permisos')->update(['lifetime' => now()->subMinute()]);
        $this->withHeader('Action-Btn', 'acfbtn01')->getJson('/api/config/appconfig')->assertForbidden();
        DB::table('permisos')->update(['lifetime' => null]);
        DB::table('vista_estados')->update(['descripcion' => 'INACTIVO']);
        $this->getJson('/api/config/appconfig')->assertForbidden();
    }

    public function test_catalog_registration_is_idempotent_and_does_not_grant_access(): void
    {
        $this->migration()->up();
        $this->assertDatabaseCount('vistas', 1);
        $this->assertDatabaseCount('actionsvistas', 3);
        $this->assertDatabaseCount('permisos', 3);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_07_000003_register_app_config_permissions.php');
    }

    private function payload(): array
    {
        return ['appv' => str_repeat('a', 64), 'mail_alert' => 'alerts@example.test', 'mail_cc1' => 'admin@example.test', 'mail_cc2' => null, 'mail_cc3' => null];
    }
}
