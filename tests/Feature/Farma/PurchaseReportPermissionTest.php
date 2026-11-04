<?php

namespace Tests\Feature\Farma;

use App\Http\Middleware\EnsureProductCostReportPermission;
use App\Http\Middleware\EnsurePurchaseReportPermission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseReportPermissionTest extends TestCase
{
    public function test_cost_report_catalog_and_guard_are_independent_and_do_not_grant_permissions(): void
    {
        $migration = require database_path('migrations/2026_10_31_000003_product_cost_report_permissions.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('actionsvistas', 2);
        $this->assertDatabaseCount('permisos', 0);
        $view = DB::table('vistas')->where('codigo', 'rct')->value('id');
        $action = DB::table('actionsvistas')->where('codigo', 'rctbtn01')->value('id');
        $user = (new User)->forceFill(['id' => 7]);
        $request = Request::create('/api/farma/reporte-costeo/consultar');
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Action-Btn', 'rctbtn01');
        $guard = new EnsureProductCostReportPermission;
        $next = fn () => response()->json(['ok' => true]);
        $this->assertSame(403, $guard->handle($request, $next)->getStatusCode());
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => $view, 'actionvista' => $action]);
        $this->assertSame(200, $guard->handle($request, $next)->getStatusCode());
        $request->headers->set('Action-Btn', 'rctbtn02');
        $this->assertSame(403, $guard->handle($request, $next)->getStatusCode());
        $request->headers->set('Action-Btn', 'rctbtn01');
        DB::table('permisos')->update(['lifetime' => now()->subMinute()]);
        $this->assertSame(403, $guard->handle($request, $next)->getStatusCode());
        DB::table('permisos')->update(['lifetime' => null]);
        DB::table('vistas')->where('id', $view)->update(['modulo' => 99]);
        $this->assertSame(403, $guard->handle($request, $next)->getStatusCode());
        $migration->down();
        $this->assertDatabaseHas('actionsvistas', ['codigo' => 'rctbtn01']);
    }

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
            DB::table($name)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        foreach (['modulos', 'vistas', 'actionsvistas'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('codigo')->unique();
                $table->string('nombre');
                if ($name !== 'actionsvistas') {
                    $table->integer('estado');
                }
                if ($name === 'vistas') {
                    $table->integer('modulo');
                }
                if ($name === 'actionsvistas') {
                    $table->integer('vista');
                }
                $table->timestamps();
            });
        }
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            $table->integer('usuario');
            $table->integer('modulo');
            $table->integer('vista');
            $table->integer('actionvista');
            $table->timestamp('lifetime')->nullable();
        });
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm', 'nombre' => 'farma', 'estado' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_permission_catalog_is_idempotent_and_guard_checks_header_expiry_and_hierarchy(): void
    {
        $migration = require database_path('migrations/2026_10_17_000005_register_purchase_report_permissions.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('vistas', 1);
        $this->assertDatabaseCount('actionsvistas', 2);
        $view = DB::table('vistas')->where('codigo', 'rcp')->value('id');
        $action = DB::table('actionsvistas')->where('codigo', 'rcpbtn01')->value('id');
        $request = Request::create('/api/farma/reporte-compras/listar');
        $user = new User;
        $user->id = 7;
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Action-Btn', 'rcpbtn01');
        $guard = new EnsurePurchaseReportPermission;
        $next = fn () => response()->json(['ok' => true]);
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn01')->getStatusCode());
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => $view, 'actionvista' => $action]);
        $this->assertSame(200, $guard->handle($request, $next, 'rcpbtn01')->getStatusCode());
        $request->headers->set('Action-Btn', 'cmpbtn01');
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn01')->getStatusCode());
        $request->headers->set('Action-Btn', 'rcpbtn01');
        DB::table('permisos')->update(['lifetime' => now()->subDay()]);
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn01')->getStatusCode());
        DB::table('permisos')->update(['lifetime' => now()->addDay()]);
        $this->assertSame(200, $guard->handle($request, $next, 'rcpbtn01')->getStatusCode());
        DB::table('vistas')->where('id', $view)->update(['modulo' => 99]);
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn01')->getStatusCode());
        DB::table('vistas')->where('id', $view)->update(['modulo' => 1]);
        DB::table('vista_estados')->update(['descripcion' => 'INACTIVO']);
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn01')->getStatusCode());
        $migration->down();
        $this->assertDatabaseHas('actionsvistas', ['codigo' => 'rcpbtn01']);
        $this->assertDatabaseMissing('actionsvistas', ['codigo' => 'rcpbtn02']);
    }

    public function test_export_has_its_own_permission_and_its_migration_is_idempotent(): void
    {
        (require database_path('migrations/2026_10_17_000005_register_purchase_report_permissions.php'))->up();
        $migration = require database_path('migrations/2026_10_17_000007_register_purchase_report_export_permission.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('actionsvistas', 3);
        $view = DB::table('vistas')->where('codigo', 'rcp')->value('id');
        $action = DB::table('actionsvistas')->where('codigo', 'rcpbtn04')->value('id');
        $user = new User;
        $user->id = 7;
        $request = Request::create('/api/farma/reporte-compras/exportar');
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Action-Btn', 'rcpbtn04');
        $guard = new EnsurePurchaseReportPermission;
        $next = fn () => response()->json(['ok' => true]);
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn04')->getStatusCode());
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => $view, 'actionvista' => $action]);
        $this->assertSame(200, $guard->handle($request, $next, 'rcpbtn04')->getStatusCode());
        $request->headers->set('Action-Btn', 'rcpbtn01');
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn04')->getStatusCode());
        $migration->down();
        $this->assertDatabaseHas('actionsvistas', ['codigo' => 'rcpbtn04']);
    }

    public function test_payments_permission_is_independent_and_preserves_assigned_permissions_on_rollback(): void
    {
        (require database_path('migrations/2026_10_17_000005_register_purchase_report_permissions.php'))->up();
        $migration = require database_path('migrations/2026_10_17_000006_register_purchase_report_payments_permission.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('actionsvistas', 3);
        $view = DB::table('vistas')->where('codigo', 'rcp')->value('id');
        $action = DB::table('actionsvistas')->where('codigo', 'rcpbtn03')->value('id');
        $user = new User;
        $user->id = 7;
        $request = Request::create('/api/farma/reporte-compras/pagos/1');
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Action-Btn', 'rcpbtn03');
        $guard = new EnsurePurchaseReportPermission;
        $next = fn () => response()->json(['ok' => true]);
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn03')->getStatusCode());
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => $view, 'actionvista' => $action]);
        $this->assertSame(200, $guard->handle($request, $next, 'rcpbtn03')->getStatusCode());
        $request->headers->set('Action-Btn', 'rcpbtn01');
        $this->assertSame(403, $guard->handle($request, $next, 'rcpbtn03')->getStatusCode());
        $migration->down();
        $this->assertDatabaseHas('actionsvistas', ['codigo' => 'rcpbtn03']);
        DB::table('permisos')->delete();
        $migration->down();
        $this->assertDatabaseMissing('actionsvistas', ['codigo' => 'rcpbtn03']);
    }
}
