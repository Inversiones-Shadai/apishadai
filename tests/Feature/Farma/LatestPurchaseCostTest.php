<?php

namespace Tests\Feature\Farma;

use App\Models\Farma\Compra;
use App\Repositories\Farma\PurchaseRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LatestPurchaseCostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->string('estado');
        });
        Schema::create('compra_detalle', function (Blueprint $table): void {
            $table->boolean('es_bonificacion')->default(false);
            $table->id();
            $table->integer('producto');
            $table->integer('compra');
            $table->decimal('costo', 10, 2);
            $table->index(['producto', 'es_bonificacion', 'compra', 'id', 'costo'], 'compra_detalle_producto_bonificacion_costo_index');
        });
        $migration = require database_path('migrations/2026_10_07_000006_index_purchase_detail_latest_cost.php');
        $migration->up();
        DB::table('productos')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        DB::table('compras')->insert([
            ['id' => 1, 'estado' => 'pagada'],
            ['id' => 2, 'estado' => 'pendiente'],
            ['id' => 3, 'estado' => 'borrador'],
        ]);
        DB::table('compra_detalle')->insert([
            ['id' => 1, 'producto' => 1, 'compra' => 2, 'costo' => 25.35],
            ['id' => 2, 'producto' => 2, 'compra' => 1, 'costo' => 40],
            ['id' => 3, 'producto' => 1, 'compra' => 3, 'costo' => 99],
            ['id' => 4, 'producto' => 1, 'compra' => 1, 'costo' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        Schema::dropAllTables();
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_it_uses_latest_purchase_order_ignores_drafts_and_handles_no_history(): void
    {
        $this->assertSame([
            ['producto_id' => 1, 'costo' => '25.35'],
            ['producto_id' => 2, 'costo' => '40.00'],
            ['producto_id' => 3, 'costo' => null],
        ], (new PurchaseRepository(new Compra))->latestCosts([1, 2, 3]));
    }

    public function test_it_excludes_the_edited_purchase_and_uses_the_latest_detail_as_tie_breaker(): void
    {
        DB::table('compra_detalle')->insert(['id' => 5, 'producto' => 1, 'compra' => 2, 'costo' => 30]);
        $repository = new PurchaseRepository(new Compra);
        $this->assertSame('30.00', $repository->latestCosts([1])[0]['costo']);
        $this->assertSame('10.00', $repository->latestCosts([1], 2)[0]['costo']);
        DB::table('compra_detalle')->insert(['id' => 6, 'producto' => 1, 'compra' => 2, 'costo' => 0]);
        $this->assertSame('30.00', $repository->latestCosts([1])[0]['costo']);
    }

    public function test_endpoint_returns_a_batch_and_validates_its_bounds(): void
    {
        $this->withoutMiddleware();
        $this->getJson('/api/farma/compras/ultimos-costos?'.http_build_query(['productos' => [1, 2], 'excluir_compra' => 2]))
            ->assertOk()->assertJsonPath('data.0.costo', '10.00')->assertJsonPath('data.1.costo', '40.00');
        foreach ([[], ['productos' => [0]], ['productos' => [1, 1]], ['productos' => range(1, 201)], ['productos' => [1], 'excluir_compra' => -1]] as $query) {
            $this->getJson('/api/farma/compras/ultimos-costos?'.http_build_query($query))->assertUnprocessable();
        }
    }

    public function test_bonus_cost_does_not_replace_the_last_normal_purchase_cost(): void
    {
        DB::table('compra_detalle')->insert(['id' => 10, 'producto' => 1, 'compra' => 2, 'costo' => 100, 'es_bonificacion' => true]);
        $this->assertSame('25.35', (new PurchaseRepository(new Compra))->latestCosts([1])[0]['costo']);
    }

    public function test_large_history_uses_one_query_and_the_covering_index_without_a_sort(): void
    {
        DB::table('compra_detalle')->delete();
        DB::table('compras')->delete();
        DB::table('productos')->delete();
        DB::table('productos')->insert(array_map(static fn (int $id): array => ['id' => $id], range(1, 200)));
        DB::table('compras')->insert(array_map(static fn (int $id): array => ['id' => $id, 'estado' => 'pendiente'], range(1, 50)));
        $rows = [];
        foreach (range(1, 200) as $product) {
            foreach (range(1, 50) as $purchase) {
                $rows[] = ['producto' => $product, 'compra' => $purchase, 'costo' => $purchase + 0.25];
            }
        }
        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('compra_detalle')->insert($batch);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $costs = (new PurchaseRepository(new Compra))->latestCosts(range(1, 200));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(200, $costs);
        $this->assertCount(1, $queries);
        $this->assertSame(['50.25'], array_values(array_unique(array_column($costs, 'costo'))));
        $plan = json_encode(DB::select('EXPLAIN QUERY PLAN '.$queries[0]['query'], $queries[0]['bindings']), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('COVERING INDEX compra_detalle_producto_bonificacion_costo_index', $plan);
        $this->assertStringNotContainsString('USE TEMP B-TREE', $plan);
    }

    public function test_index_migration_is_repeatable_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_10_07_000006_index_purchase_detail_latest_cost.php');
        $migration->up();
        $this->assertTrue(Schema::hasIndex('compra_detalle', 'compra_detalle_producto_compra_costo_index'));
        $migration->down();
        $this->assertFalse(Schema::hasIndex('compra_detalle', 'compra_detalle_producto_compra_costo_index'));
        $migration->up();
        $this->assertTrue(Schema::hasIndex('compra_detalle', 'compra_detalle_producto_compra_costo_index'));
    }
}
