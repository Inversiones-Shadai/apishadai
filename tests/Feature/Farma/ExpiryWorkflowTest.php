<?php

namespace Tests\Feature\Farma;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Models\User;
use App\Services\Farma\ExpiryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpiryWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', ExpiryService::TIMEZONE));
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
        });
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->integer('proveedor')->nullable();
            $table->integer('plazo')->default(30);
            $table->string('nro');
        });
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->string('codigobar')->nullable();
            $table->string('descripcion');
            $table->binary('imagen')->nullable();
            $table->integer('producto_padre_id')->nullable();
        });
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->integer('producto');
            $table->integer('compra')->nullable();
            $table->string('lote');
            $table->date('fecha_exp')->nullable();
            $table->decimal('cantidad', 12, 4)->nullable();
        });
        (require database_path('migrations/2026_10_20_000002_supplier_return_rules.php'))->up();
        Schema::create('compra_detalle', fn (Blueprint $table) => [$table->id(), $table->integer('producto'), $table->integer('compra'), $table->decimal('costo')]);
        (require database_path('migrations/2026_10_20_000004_purchase_line_bonuses.php'))->up();
        DB::table('proveedores')->insert([['id' => 1, 'nombre' => 'Proveedor A'], ['id' => 2, 'nombre' => 'Proveedor B']]);
        DB::table('compras')->insert(['id' => 1, 'proveedor' => 1, 'nro' => 'FA-1', 'en_escala' => false, 'plazo' => 900]);
        DB::table('productos')->insert(['id' => 1, 'codigo' => 'P-001', 'codigobar' => '7501234567890', 'descripcion' => 'Amoxicilina', 'imagen' => 'test']);
        DB::table('lotes')->insert(['id' => 1, 'producto' => 1, 'compra' => 1, 'lote' => 'LOTE-1', 'fecha_exp' => '2027-01-20', 'cantidad' => 12.5]);
        $this->createPermissions();
        $user = new User;
        $user->id = 7;
        $this->actingAs($user);
        $this->withoutMiddleware([Authenticate::class, DeviceSecurityMiddleware::class, IdempotencyMiddleware::class, JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_search_supports_code_barcode_description_and_pagination_before_selection(): void
    {
        foreach (['P-001', '7501234567890', 'Amoxi'] as $search) {
            $this->withHeader('Action-Btn', 'vncbtn01')->getJson('/api/farma/vencimientos/productos?search='.$search.'&per_page=1')
                ->assertOk()->assertJsonPath('data.0.producto_id', 1)->assertJsonPath('meta.total', 1);
        }
        $this->withHeader('Action-Btn', 'vncbtn01')->getJson('/api/farma/vencimientos/productos?meses=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->withHeader('Action-Btn', 'vncbtn02')->getJson('/api/farma/vencimientos/producto/1/lotes?meses=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->withHeader('Action-Btn', 'vncbtn02')->getJson('/api/farma/vencimientos/producto/1/lotes')->assertOk()
            ->assertJsonPath('data.0.proveedor_nombre', 'Proveedor A')->assertJsonMissingPath('data.0.factura')
            ->assertJsonPath('data.0.cantidad_entrada', 12.5)->assertJsonPath('data.0.es_bonificacion', false);
    }

    public function test_deadline_is_inclusive_and_independent_from_payment_term(): void
    {
        $this->rule();
        $result = $this->verify();
        $this->assertSame('apto', $result['estado']);
        $this->assertSame('2026-10-20', $result['fecha_limite_envio']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 00:00:00', ExpiryService::TIMEZONE));
        $this->assertSame('no_apto', $this->verify()['estado']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 05:59:00', 'UTC'));
        $this->assertSame('apto', $this->verify()['estado']);
    }

    public function test_end_of_month_and_expired_lots_are_handled_explicitly(): void
    {
        $this->rule(['meses_anticipacion' => 1]);
        DB::table('lotes')->update(['fecha_exp' => '2027-03-31']);
        $this->assertSame('2027-02-28', $this->verify()['fecha_limite_envio']);
        DB::table('lotes')->update(['fecha_exp' => '2026-10-20']);
        $this->assertSame('no_apto', $this->verify()['estado']);
        $this->assertContains('El producto está vencido.', $this->verify()['motivos']);
    }

    public function test_child_opened_and_bonus_rules_use_authoritative_lot_and_product_data(): void
    {
        $this->rule();
        DB::table('productos')->update(['producto_padre_id' => 99]);
        DB::table('lotes')->update(['es_bonificacion' => true]);
        $result = $this->verify(true);
        $this->assertSame('no_apto', $result['estado']);
        $this->assertCount(2, $result['motivos']);
        $this->rule(['acepta_hijos' => true]);
        $this->assertSame('no_apto', $this->verify(true)['estado']);
        $this->assertContains('El lote es bonificación y no acepta devolución.', $this->verify(true)['motivos']);
        DB::table('lotes')->update(['es_bonificacion' => false]);
        $this->assertSame('apto', $this->verify(true)['estado']);
        $this->rule(['acepta_hijos' => true, 'tipo_devolucion' => 'autorizacion']);
        $this->assertSame('requiere_autorizacion', $this->verify(true)['estado']);
        $this->rule(['tipo_devolucion' => 'no_acepta']);
        $this->assertSame('no_apto', $this->verify()['estado']);
    }

    public function test_unknown_policy_supplier_or_expiry_never_produces_false_acceptance(): void
    {
        $this->assertSame('pendiente', $this->verify()['estado']);
        $this->rule();
        $this->assertSame('apto', $this->verify(null)['estado']);
        DB::table('compras')->update(['en_escala' => null]);
        $this->assertSame('apto', $this->verify()['estado']);
        DB::table('lotes')->update(['compra' => null]);
        $this->assertSame('pendiente', $this->verify()['estado']);
        DB::table('lotes')->update(['fecha_exp' => null]);
        $this->assertSame('pendiente', $this->verify()['estado']);
        $missing = app(ExpiryService::class)->verify([['id' => 999, 'producto_empezado' => false]]);
        $this->assertSame('pendiente', $missing['resultados'][0]['estado']);
    }

    public function test_multiple_lots_are_evaluated_by_their_own_supplier_even_when_product_and_lot_code_match(): void
    {
        $this->rule();
        DB::table('compras')->insert(['id' => 2, 'proveedor' => 2, 'nro' => 'FB-2', 'en_escala' => false]);
        DB::table('lotes')->insert(['id' => 2, 'producto' => 1, 'compra' => 2, 'lote' => 'LOTE-1', 'fecha_exp' => '2027-01-20', 'cantidad' => 10]);
        $this->withHeader('Action-Btn', 'vncbtn03')->postJson('/api/farma/vencimientos/verificar', ['lotes' => [
            ['id' => 1, 'producto_empezado' => false], ['id' => 2, 'producto_empezado' => false],
        ]])->assertOk()->assertJsonPath('data.resultados.0.estado', 'apto')->assertJsonPath('data.resultados.1.estado', 'pendiente')
            ->assertJsonPath('data.resultados.1.proveedor_nombre', 'Proveedor B');
    }

    public function test_bonus_lot_is_rejected_independently_of_normal_lot_in_the_same_purchase(): void
    {
        $this->rule(['acepta_hijos' => true]);
        DB::table('compras')->update(['en_escala' => true]);
        DB::table('lotes')->insert(['id' => 2, 'producto' => 1, 'compra' => 1, 'lote' => 'LOTE-1', 'fecha_exp' => '2027-01-20', 'cantidad' => 2, 'es_bonificacion' => true]);
        $this->withHeader('Action-Btn', 'vncbtn03')->postJson('/api/farma/vencimientos/verificar', ['lotes' => [
            ['id' => 1, 'producto_empezado' => false], ['id' => 2, 'producto_empezado' => false],
        ]])->assertOk()->assertJsonPath('data.resultados.0.estado', 'apto')->assertJsonPath('data.resultados.1.estado', 'no_apto')
            ->assertJsonPath('data.resultados.1.es_bonificacion', true)->assertJsonMissingPath('data.resultados.0.factura');
        DB::table('proveedor_reglas_devolucion')->delete();
        DB::table('lotes')->where('id', 1)->update(['es_bonificacion' => true, 'compra' => null]);
        $this->assertSame('no_apto', $this->verify()['estado']);
    }

    public function test_catalog_alone_classifies_children_and_parents_regardless_of_legacy_opened_input(): void
    {
        $this->rule();
        $this->withHeader('Action-Btn', 'vncbtn03')->postJson('/api/farma/vencimientos/verificar', ['lotes' => [['id' => 1]]])
            ->assertOk()->assertJsonPath('data.resultados.0.estado', 'apto')->assertJsonPath('data.resultados.0.es_hijo', false);
        // A stale client cannot turn a parent into an opened product.
        $this->assertSame('apto', $this->verify(true)['estado']);
        DB::table('productos')->update(['producto_padre_id' => 99]);
        // Nor can it describe a child as complete to bypass the supplier rule.
        $this->assertSame('no_apto', $this->verify(false)['estado']);
        $this->assertContains('El proveedor no acepta productos hijos / empezados.', $this->verify()['motivos']);
        $this->rule(['acepta_hijos' => true]);
        $this->assertSame('apto', $this->verify(null)['estado']);
        $this->assertDatabaseHas('proveedor_reglas_devolucion', ['proveedor_id' => 1, 'acepta_hijos' => true, 'acepta_empezados' => true]);
        $this->withHeader('Action-Btn', 'rdpbtn01')->getJson('/api/farma/reglas-proveedores/1')->assertOk()
            ->assertJsonPath('data.regla.acepta_hijos', true)->assertJsonMissingPath('data.regla.acepta_empezados');
    }

    public function test_conflicting_legacy_rules_are_rejected_and_migration_normalizes_existing_policies_once(): void
    {
        $this->withHeader('Action-Btn', 'rdpbtn02')->putJson('/api/farma/reglas-proveedores/1', $this->rulePayload([
            'acepta_hijos' => true, 'acepta_empezados' => false,
        ]))->assertStatus(422);
        $this->assertDatabaseCount('proveedor_reglas_devolucion', 0);
        $this->rule(['acepta_hijos' => true]);
        DB::table('proveedor_reglas_devolucion')->where('proveedor_id', 1)->update(['acepta_empezados' => false]);
        app(ExpiryService::class)->savePolicy(2, $this->rulePayload(), 7);
        DB::table('proveedor_reglas_devolucion')->where('proveedor_id', 2)->update(['acepta_empezados' => true]);
        $migration = require database_path('migrations/2026_10_20_000005_unify_supplier_child_rule.php');
        $migration->up();
        $this->assertDatabaseHas('proveedor_reglas_devolucion', ['proveedor_id' => 1, 'acepta_hijos' => true, 'acepta_empezados' => true, 'revision' => 2]);
        $this->assertDatabaseHas('proveedor_reglas_devolucion', ['proveedor_id' => 2, 'acepta_hijos' => false, 'acepta_empezados' => false, 'revision' => 2]);
        $migration->up();
        $this->assertSame(2, (int) DB::table('proveedor_reglas_devolucion')->where('proveedor_id', 1)->value('revision'));
        $this->assertSame('conflict', app(ExpiryService::class)->savePolicy(1, $this->rulePayload(['revision' => 1]), 7));
    }

    public function test_rules_are_saved_separately_with_revision_conflicts_and_no_payment_term_changes(): void
    {
        $payload = $this->rulePayload();
        $this->withHeader('Action-Btn', 'rdpbtn02')->putJson('/api/farma/reglas-proveedores/1', $payload)->assertOk()->assertJsonPath('data.regla.revision', 1);
        $this->withHeader('Action-Btn', 'rdpbtn02')->putJson('/api/farma/reglas-proveedores/1', $payload)->assertStatus(409);
        $payload['revision'] = 1;
        $payload['meses_anticipacion'] = 6;
        $this->withHeader('Action-Btn', 'rdpbtn02')->putJson('/api/farma/reglas-proveedores/1', $payload)->assertOk()->assertJsonPath('data.regla.revision', 2);
        $this->assertSame(900, DB::table('compras')->value('plazo'));
        $this->withHeader('Action-Btn', 'rdpbtn01')->getJson('/api/farma/reglas-proveedores/2')->assertOk()->assertJsonPath('data.regla', null);
        $this->withHeader('Action-Btn', 'rdpbtn01')->getJson('/api/farma/reglas-proveedores/999')->assertNotFound();
        $this->withHeader('Action-Btn', 'rdpbtn02')->putJson('/api/farma/reglas-proveedores/1', [...$payload, 'meses_anticipacion' => -1])->assertStatus(422);
    }

    public function test_verify_rejects_duplicate_oversized_or_forged_inputs(): void
    {
        $item = ['id' => 1, 'producto_empezado' => false];
        $this->withHeader('Action-Btn', 'vncbtn03')->postJson('/api/farma/vencimientos/verificar', ['lotes' => [$item, $item]])->assertStatus(422);
        $this->withHeader('Action-Btn', 'vncbtn03')->postJson('/api/farma/vencimientos/verificar', ['lotes' => array_fill(0, 101, $item)])->assertStatus(422);
        $this->withHeader('Action-Btn', 'vncbtn03')->postJson('/api/farma/vencimientos/verificar', ['lotes' => [[...$item, 'proveedor_id' => 2]]])->assertStatus(422);
    }

    public function test_catalog_is_idempotent_and_permissions_are_enforced_on_each_api_operation(): void
    {
        $migration = require database_path('migrations/2026_10_20_000003_supplier_return_permissions.php');
        $migration->up();
        $this->assertDatabaseCount('vistas', 2);
        $this->assertDatabaseCount('actionsvistas', 5);
        $this->withHeader('Action-Btn', 'vncbtn01')->getJson('/api/farma/vencimientos/productos')->assertOk();
        $this->withHeader('Action-Btn', 'rdpbtn01')->getJson('/api/farma/vencimientos/productos')->assertForbidden();
        DB::table('permisos')->update(['lifetime' => now()->subDay()]);
        $this->withHeader('Action-Btn', 'vncbtn01')->getJson('/api/farma/vencimientos/productos')->assertForbidden();
        $this->withHeader('Action-Btn', 'vncbtn03')->postJson('/api/farma/vencimientos/verificar', ['lotes' => [['id' => 1, 'producto_empezado' => false]]])->assertForbidden();
        $this->withHeader('Action-Btn', 'rdpbtn02')->putJson('/api/farma/reglas-proveedores/1', $this->rulePayload())->assertForbidden();
        DB::table('permisos')->update(['lifetime' => null]);
        DB::table('vistas')->where('codigo', 'vnc')->update(['modulo' => 999]);
        $this->withHeader('Action-Btn', 'vncbtn01')->getJson('/api/farma/vencimientos/productos')->assertForbidden();
        $this->assertDatabaseCount('proveedor_reglas_devolucion', 0);
    }

    private function verify(?bool $opened = false): array
    {
        return app(ExpiryService::class)->verify([['id' => 1, 'producto_empezado' => $opened]])['resultados'][0];
    }

    private function rulePayload(array $overrides = []): array
    {
        return [...['meses_anticipacion' => 3, 'acepta_hijos' => false, 'tipo_devolucion' => 'directa', 'notas' => '', 'revision' => 0], ...$overrides];
    }

    private function rule(array $overrides = []): void
    {
        $revision = (int) DB::table('proveedor_reglas_devolucion')->where('proveedor_id', 1)->value('revision');
        $this->assertSame('saved', app(ExpiryService::class)->savePolicy(1, $this->rulePayload(['revision' => $revision, ...$overrides]), 7));
    }

    private function createPermissions(): void
    {
        foreach (['modulo_estados', 'vista_estados'] as $name) {
            Schema::create($name, fn (Blueprint $table) => [$table->id(), $table->string('descripcion')]);
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
        Schema::create('permisos', fn (Blueprint $table) => [$table->id(), $table->integer('usuario'), $table->integer('modulo'), $table->integer('vista'), $table->integer('actionvista'), $table->timestamp('lifetime')->nullable()]);
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm', 'nombre' => 'Farma', 'estado' => 1]);
        (require database_path('migrations/2026_10_20_000003_supplier_return_permissions.php'))->up();
        foreach (DB::table('actionsvistas')->get() as $action) {
            DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => $action->vista, 'actionvista' => $action->id]);
        }
    }
}
