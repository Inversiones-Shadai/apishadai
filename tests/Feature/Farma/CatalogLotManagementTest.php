<?php

namespace Tests\Feature\Farma;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogLotManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, IdempotencyMiddleware::class, JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class]);
        $this->actingAs((new User)->forceFill(['id' => 7]));
        foreach (['modulo_estados', 'vista_estados'] as $name) {
            Schema::create($name, fn (Blueprint $table) => [$table->id(), $table->string('descripcion')]);
            DB::table($name)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        Schema::create('modulos', fn (Blueprint $table) => [$table->id(), $table->string('codigo'), $table->integer('estado')]);
        Schema::create('vistas', fn (Blueprint $table) => [$table->id(), $table->integer('modulo'), $table->string('codigo'), $table->integer('estado')]);
        Schema::create('actionsvistas', fn (Blueprint $table) => [$table->id(), $table->integer('vista'), $table->string('codigo'), $table->string('nombre'), $table->timestamps()]);
        Schema::create('permisos', fn (Blueprint $table) => [$table->id(), $table->integer('usuario'), $table->integer('modulo'), $table->integer('vista'), $table->integer('actionvista'), $table->timestamp('lifetime')->nullable()]);
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm', 'estado' => 1]);
        DB::table('vistas')->insert(['id' => 1, 'modulo' => 1, 'codigo' => 'dst', 'estado' => 1]);
        (require database_path('migrations/2026_11_07_000004_register_distribution_catalog_lot_permission.php'))->up();
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => 1, 'actionvista' => DB::table('actionsvistas')->value('id')]);
        $this->withHeader('Action-Btn', 'dstbtn04');
        Schema::create('productos', fn (Blueprint $table) => [$table->id(), $table->string('codigo'), $table->string('codigobar')->nullable(), $table->string('descripcion')]);
        DB::table('productos')->insert(['id' => 4, 'codigo' => 'P004', 'descripcion' => 'Producto sin lotes']);
        Schema::create('pos_compra_lote_mutex', fn (Blueprint $table) => $table->integer('id')->primary());
        DB::table('pos_compra_lote_mutex')->insert(['id' => 1]);
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->integer('producto');
            $table->integer('compra')->nullable();
            $table->string('lote', 100);
            $table->date('fecha_elab')->nullable();
            $table->date('fecha_exp')->nullable();
            $table->decimal('cantidad', 19, 4)->nullable();
            $table->decimal('costo', 19, 4)->nullable();
            $table->boolean('isv')->nullable();
            $table->timestamps();
        });
        Schema::create('distribucion', fn (Blueprint $table) => [$table->id(), $table->integer('lote')]);
        Schema::create('compras', fn (Blueprint $table) => [$table->id(), $table->string('nro')]);
        Schema::create('pos_branch_balances', fn (Blueprint $table) => [$table->id(), $table->integer('stock_id'), $table->integer('producto_id'), $table->decimal('cantidad', 19, 4)]);
        DB::table('pos_branch_balances')->insert(['stock_id' => 1, 'producto_id' => 4, 'cantidad' => '32.5000']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_catalog_lot_does_not_create_a_purchase_or_modify_stock_balances(): void
    {
        $this->postJson('/api/farma/distribucion/lote', $this->payload())->assertCreated()
            ->assertJsonPath('data.lote', 'LOCAL-001')->assertJsonPath('data.cantidad', 0)
            ->assertJsonPath('data.factura', null)->assertJsonPath('data.configurado', false)
            ->assertJsonPath('data.costo', 12.3456)->assertJsonPath('data.isv', true);
        $this->assertDatabaseHas('lotes', ['producto' => 4, 'cantidad' => 0, 'compra' => null]);
        $this->assertDatabaseHas('pos_branch_balances', ['stock_id' => 1, 'producto_id' => 4, 'cantidad' => '32.5000']);
        $this->assertDatabaseCount('pos_branch_balances', 1);
        $this->assertDatabaseCount('compras', 0);
        $this->assertDatabaseCount('distribucion', 0);
    }

    public function test_duplicate_registration_preserves_the_existing_cost_and_quantity(): void
    {
        $id = $this->postJson('/api/farma/distribucion/lote', $this->payload())->assertCreated()->json('data.id');
        DB::table('lotes')->where('id', $id)->update(['cantidad' => 14]);
        $this->postJson('/api/farma/distribucion/lote', array_replace($this->payload(), ['costo' => 99]))
            ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['lote']]]);
        $this->assertDatabaseCount('lotes', 1);
        $this->assertDatabaseHas('lotes', ['id' => $id, 'cantidad' => 14, 'costo' => 12.3456]);
    }

    public function test_dates_and_cost_are_optional_and_duplicates_with_null_dates_are_rejected(): void
    {
        $payload = ['producto' => 4, 'lote' => 'SIN-FECHAS', 'isv' => false];
        $this->postJson('/api/farma/distribucion/lote', $payload)->assertCreated()
            ->assertJsonPath('data.fecha_elaboracion', null)->assertJsonPath('data.fecha_expiracion', null)->assertJsonPath('data.costo', null);
        $this->postJson('/api/farma/distribucion/lote', $payload)->assertUnprocessable();
    }

    public function test_invalid_dates_unknown_products_and_inventory_fields_are_rejected(): void
    {
        foreach ([['fecha_elab' => '2030-01-01'], ['producto' => 999], ['cantidad' => 30], ['compra' => 2], ['stock' => 1], ['costo' => -1], ['lote' => str_repeat('a', 101)]] as $invalid) {
            $this->postJson('/api/farma/distribucion/lote', array_replace($this->payload(), $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('lotes', 0);
    }

    public function test_create_permission_cannot_be_replaced_by_pricing_permission_or_a_forged_header(): void
    {
        $this->withHeader('Action-Btn', 'dstbtn03')->postJson('/api/farma/distribucion/lote', $this->payload())->assertForbidden();
        DB::table('permisos')->delete();
        $this->withHeader('Action-Btn', 'dstbtn04')->postJson('/api/farma/distribucion/lote', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('lotes', 0);
    }

    public function test_product_selector_includes_products_without_lots_and_requires_create_permission(): void
    {
        $this->getJson('/api/selectors/distribution-products?search=P004')->assertOk()->assertJsonPath('data.0.id', '4');
        DB::table('permisos')->update(['lifetime' => now()->subMinute()]);
        $this->getJson('/api/selectors/distribution-products')->assertForbidden();
        $this->postJson('/api/farma/distribucion/lote', $this->payload())->assertForbidden();
    }

    private function payload(): array
    {
        return ['producto' => 4, 'lote' => 'LOCAL-001', 'fecha_elab' => '2026-01-01', 'fecha_exp' => '2028-01-01', 'costo' => '12.3456', 'isv' => true];
    }
}
