<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLicensePermission;
use Database\Seeders\DesktopLicensePermissionSeeder;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DesktopLicensePermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['modulo_estados', 'vista_estados'] as $name) {
            Schema::create($name, function (Blueprint $t): void {
                $t->id();
                $t->string('descripcion');
            });
            DB::table($name)->insert([['id' => 1, 'descripcion' => 'ACTIVO'], ['id' => 2, 'descripcion' => 'INACTIVO']]);
        }
        Schema::create('modulos', function (Blueprint $t): void {
            $t->id();
            $t->string('codigo');
            $t->integer('estado');
        });
        Schema::create('vistas', function (Blueprint $t): void {
            $t->id();
            $t->string('codigo');
            $t->string('nombre');
            $t->integer('modulo');
            $t->integer('estado');
            $t->timestamps();
        });
        Schema::create('actionsvistas', function (Blueprint $t): void {
            $t->id();
            $t->string('codigo');
            $t->string('nombre');
            $t->integer('vista');
            $t->timestamps();
        });
        Schema::create('permisos', function (Blueprint $t): void {
            $t->id();
            $t->integer('usuario');
            $t->integer('modulo');
            $t->integer('vista');
            $t->integer('actionvista');
            $t->timestamp('lifetime')->nullable();
        });
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'cfg', 'estado' => 1]);
        (new DesktopLicensePermissionSeeder)->run();
    }

    public function test_registers_catalog_idempotently_without_granting_any_user_permissions(): void
    {
        $ids = DB::table('actionsvistas')->pluck('id')->all();
        (new DesktopLicensePermissionSeeder)->run();
        $this->assertSame($ids, DB::table('actionsvistas')->pluck('id')->all());
        $this->assertSame(6, count($ids));
        $this->assertDatabaseCount('permisos', 0);
    }

    public function test_each_action_requires_its_own_permission_and_matching_action_header(): void
    {
        $this->grant('licbtn01');
        $this->assertTrue($this->allows('licbtn01', 'licbtn01'));
        foreach (['licbtn02', 'licbtn03', 'licbtn04', 'licbtn05', 'licbtn06'] as $action) {
            $this->assertFalse($this->allows($action, $action));
        }
        $this->assertFalse($this->allows('licbtn01', 'licbtn02'));
        $this->assertFalse($this->allows('licbtn01', 'licbtn01', null));
        $this->assertFalse($this->allows('licbtn01', 'licbtn01', 99));
    }

    public function test_deletion_requires_its_own_permission_and_route_middleware(): void
    {
        $this->grant('licbtn03');
        $this->grant('licbtn05');
        $this->assertFalse($this->allows('licbtn06', 'licbtn06'));
        $this->grant('licbtn06');
        $this->assertTrue($this->allows('licbtn06', 'licbtn06'));
        $this->assertFalse($this->allows('licbtn06', 'licbtn05'));
        $route = app('router')->getRoutes()->match(Request::create('/api/config/licenses/10000000-0000-4000-8000-000000000001', 'DELETE'));
        $this->assertContains(EnsureLicensePermission::class.':licbtn06', $route->gatherMiddleware());
    }

    public function test_expired_assignment_and_inactive_catalogs_deny_access(): void
    {
        $this->grant('licbtn01');
        DB::table('permisos')->update(['lifetime' => now()->subMinute()]);
        $this->assertFalse($this->allows('licbtn01', 'licbtn01'));
        DB::table('permisos')->update(['lifetime' => null]);
        DB::table('vistas')->update(['estado' => 2]);
        $this->assertFalse($this->allows('licbtn01', 'licbtn01'));
        DB::table('vistas')->update(['estado' => 1]);
        DB::table('modulos')->update(['estado' => 2]);
        $this->assertFalse($this->allows('licbtn01', 'licbtn01'));
    }

    private function grant(string $action): void
    {
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => DB::table('vistas')->where('codigo', 'lic')->value('id'),
            'actionvista' => DB::table('actionsvistas')->where('codigo', $action)->value('id')]);
    }

    private function allows(string $action, string $header, ?int $user = 7): bool
    {
        $request = Request::create('/api/config/licenses');
        $request->headers->set('Action-Btn', $header);
        $request->setUserResolver(fn () => $user === null ? null : new GenericUser(['id' => $user]));
        try {
            return (new EnsureLicensePermission)->handle($request, fn () => response('allowed'), $action)->getStatusCode() === 200;
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());

            return false;
        }
    }
}
