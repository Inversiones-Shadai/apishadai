<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Farma\TransferProductConversion;
use App\Services\Selectors\SelectorCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SelectorCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->withoutMiddleware();
        foreach (['modulo_estados', 'vista_estados'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('descripcion');
            });
            DB::table($table)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        Schema::create('modulos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->integer('estado');
        });
        Schema::create('vistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->integer('estado');
            $table->integer('modulo');
        });
        Schema::create('actionsvistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->integer('vista');
        });
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            $table->integer('usuario');
            $table->integer('modulo');
            $table->integer('vista');
            $table->integer('actionvista');
            $table->timestamp('lifetime')->nullable();
        });
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm', 'estado' => 1]);
        DB::table('vistas')->insert(['id' => 1, 'codigo' => 'prd', 'estado' => 1, 'modulo' => 1]);
        DB::table('actionsvistas')->insert(['id' => 1, 'codigo' => 'prdbtn07', 'vista' => 1]);
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => 1, 'actionvista' => 1]);
        $this->actingAs((new User)->forceFill(['id' => 7]));
        $this->withHeader('Action-Btn', 'prdbtn07');
        Schema::create('prod_categorias', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('private_notes')->nullable();
        });
        foreach (range(1, 34) as $id) {
            DB::table('prod_categorias')->insert(['id' => $id, 'nombre' => 'Categoría '.$id, 'private_notes' => 'Not public']);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_catalogs_are_paged_at_ten_and_search_applies_before_paging(): void
    {
        $this->getJson('/api/selectors/product-categories')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 34);
        $this->getJson('/api/selectors/product-categories?page=4')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/selectors/product-categories?search=Categor%C3%ADa%2034')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '34');
        $this->getJson('/api/selectors/product-categories?selected=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '1');
        $this->getJson('/api/selectors/product-categories?per_page=5000&all=1')->assertOk()->assertJsonCount(10, 'data');
        $this->assertArrayNotHasKey('private_notes', $this->getJson('/api/selectors/product-categories')->json('data.0.record'));
    }

    public function test_missing_expired_inactive_and_wrong_context_permissions_cannot_read_a_catalog(): void
    {
        $this->withHeader('Action-Btn', 'wrong')->getJson('/api/selectors/product-categories')->assertForbidden();
        $this->withHeader('Action-Btn', 'prdbtn07');
        DB::table('permisos')->update(['lifetime' => now()->subSecond()]);
        $this->getJson('/api/selectors/product-categories')->assertForbidden();
        DB::table('permisos')->update(['lifetime' => null]);
        DB::table('modulo_estados')->update(['descripcion' => 'INACTIVO']);
        $this->getJson('/api/selectors/product-categories')->assertForbidden();
    }

    public function test_parent_scopes_apply_to_search_and_selected_record_resolution(): void
    {
        config(['selector_sources.product-categories.parents' => ['parent' => 'group_id']]);
        Schema::table('prod_categorias', fn (Blueprint $table) => $table->integer('group_id')->default(1));
        DB::table('prod_categorias')->where('id', 34)->update(['group_id' => 2]);
        $this->getJson('/api/selectors/product-categories')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/selectors/product-categories?filters[parent]=1&selected=34')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/selectors/product-categories?filters[parent]=2&selected=34')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_treats_wildcards_as_text_and_validation_rejects_invalid_parameters(): void
    {
        DB::table('prod_categorias')->insert(['nombre' => 'Literal %_!']);
        $this->getJson('/api/selectors/product-categories?search=%25_!')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/selectors/product-categories?selected=bad')->assertUnprocessable();
        $this->getJson('/api/selectors/product-categories?page=0')->assertUnprocessable();
        $this->getJson('/api/selectors/product-categories?search='.str_repeat('a', 256))->assertUnprocessable();
        $this->getJson('/api/selectors/users-passwords')->assertNotFound();
    }

    public function test_purchase_choices_keep_balances_and_exclude_drafts(): void
    {
        Schema::create('proveedores', function (Blueprint $t): void {
            $t->id();
            $t->string('nombre');
        });
        Schema::create('compras', function (Blueprint $t): void {
            $t->id();
            $t->integer('proveedor');
            $t->string('nro');
            $t->string('estado');
            $t->decimal('total');
        });
        Schema::create('compra_transc', function (Blueprint $t): void {
            $t->id();
            $t->integer('compra');
            $t->decimal('valor');
        });
        DB::table('proveedores')->insert(['id' => 1, 'nombre' => 'Proveedor ejemplo']);
        DB::table('compras')->insert([
            ['id' => 1, 'proveedor' => 1, 'nro' => 'F-001', 'estado' => 'pendiente', 'total' => 100],
            ['id' => 2, 'proveedor' => 1, 'nro' => 'F-002', 'estado' => 'borrador', 'total' => 200],
        ]);
        DB::table('compra_transc')->insert([['compra' => 1, 'valor' => 20], ['compra' => 1, 'valor' => 5]]);
        $catalog = app(SelectorCatalog::class);
        $page = $catalog->page($catalog->definition('payment-purchases'), ['search' => 'Proveedor ejemplo']);
        $this->assertSame(1, $page->total());
        $this->assertSame(25.0, $page->items()[0]['record']['total_aplicado']);
        $this->assertSame(75.0, $page->items()[0]['record']['saldo']);
        $this->assertStringContainsString('75.00', $page->items()[0]['label']);
        $this->assertSame(0, $catalog->page($catalog->definition('payment-purchases'), ['selected' => '2'])->total());
    }

    public function test_ticket_client_search_is_bounded_and_keeps_browse_and_search_permissions_separate(): void
    {
        Schema::create('clientes', function (Blueprint $t): void {
            $t->id();
            foreach (['docid', 'pnombre', 'snombre', 'papellido', 'spaellido', 'telefono'] as $column) {
                $t->string($column)->default('');
            }
        });
        foreach (range(1, 34) as $id) {
            DB::table('clientes')->insert(['id' => $id, 'docid' => '0801'.$id, 'pnombre' => 'Ana', 'papellido' => 'Ejemplo', 'telefono' => 'Privado']);
        }
        foreach (['ticket-clients', 'ticket-client-search'] as $source) {
            config(['selector_sources.'.$source.'.permissions' => [['frm', 'prd', 'prdbtn07']]]);
        }
        $this->getJson('/api/selectors/ticket-clients')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson('/api/selectors/ticket-clients?search=Ana')->assertForbidden();
        $this->getJson('/api/selectors/ticket-client-search')->assertUnprocessable();
        $this->getJson('/api/selectors/ticket-client-search?search=A')->assertUnprocessable();
        $this->getJson('/api/selectors/ticket-client-search?search=Ana&page=4')->assertOk()->assertJsonCount(4, 'data');
        $result = $this->getJson('/api/selectors/ticket-client-search?search=080134')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '34');
        $this->assertArrayNotHasKey('telefono', $result->json('data.0.record'));
    }

    public function test_active_roles_can_be_searched_and_an_existing_inactive_role_can_be_resolved(): void
    {
        Schema::create('rolespos', function (Blueprint $t): void {
            $t->id();
            $t->string('nombre');
            $t->boolean('activo');
        });
        DB::table('rolespos')->insert([['id' => 1, 'nombre' => 'Activo', 'activo' => true], ['id' => 2, 'nombre' => 'Anterior', 'activo' => false]]);
        $catalog = app(SelectorCatalog::class);
        $definition = $catalog->definition('pos-roles');
        $this->assertSame('1', $catalog->page($definition, [])->items()[0]['id']);
        $this->assertSame(1, $catalog->page($definition, [])->total());
        $this->assertSame('2', $catalog->page($definition, ['selected' => '2'])->items()[0]['id']);
    }

    public function test_conversion_choices_are_compatible_scoped_and_compact(): void
    {
        Schema::create('prod_unidades', function (Blueprint $t): void {
            $t->id();
            $t->integer('cantidad_c');
            $t->integer('cantidad_v');
        });
        Schema::create('productos', function (Blueprint $t): void {
            $t->id();
            $t->string('codigo');
            $t->string('descripcion');
            $t->integer('unidad');
            $t->integer('producto_padre_id')->nullable();
        });
        Schema::create('lotes', function (Blueprint $t): void {
            $t->id();
            $t->integer('producto');
            $t->integer('cantidad');
        });
        DB::table('prod_unidades')->insert([['id' => 1, 'cantidad_c' => 100, 'cantidad_v' => 1], ['id' => 2, 'cantidad_c' => 3, 'cantidad_v' => 3]]);
        DB::table('productos')->insert(['id' => 1, 'codigo' => 'P', 'descripcion' => 'Padre', 'unidad' => 1, 'producto_padre_id' => null]);
        foreach (range(101, 112) as $id) {
            DB::table('productos')->insert(['id' => $id, 'codigo' => 'H-'.$id, 'descripcion' => 'Hijo '.$id, 'unidad' => 1, 'producto_padre_id' => 1]);
        }
        DB::table('productos')->insert(['id' => 2, 'codigo' => 'I', 'descripcion' => 'Incompatible', 'unidad' => 2, 'producto_padre_id' => 1]);
        DB::table('lotes')->insert(['id' => 1, 'producto' => 1, 'cantidad' => 10]);
        $catalog = app(SelectorCatalog::class);
        $definition = $catalog->definition('transfer-children');
        $this->assertSame(12, $catalog->page($definition, ['filters' => ['parent' => 1]])->total());
        $this->assertCount(10, $catalog->page($definition, ['filters' => ['parent' => 1]])->items());
        $this->assertSame(0, $catalog->page($definition, ['filters' => ['parent' => 1], 'selected' => '2'])->total());
        $this->assertSame(0, $catalog->page($definition, ['filters' => ['parent' => 99]])->total());
        $options = app(TransferProductConversion::class)->options(1, true);
        $this->assertCount(10, $options['hijos']);
        $this->assertSame(12, $options['hijos_total']);
    }

    public function test_distribution_lot_choices_keep_cost_tax_and_prices_in_product_scope(): void
    {
        Schema::create('lotes', function (Blueprint $t): void {
            $t->id();
            $t->integer('producto');
            $t->integer('compra')->nullable();
            $t->string('lote');
            $t->integer('cantidad');
            $t->decimal('costo');
            $t->boolean('isv');
        });
        Schema::create('distribucion', function (Blueprint $t): void {
            $t->id();
            $t->integer('lote');
            $t->decimal('precio');
            $t->boolean('isv');
            foreach (range(1, 4) as $i) {
                $t->decimal('dto'.$i)->default(0);
            }
        });
        DB::table('lotes')->insert([['id' => 1, 'producto' => 7, 'lote' => 'L-001', 'cantidad' => 5, 'costo' => 10, 'isv' => true], ['id' => 2, 'producto' => 8, 'lote' => 'L-002', 'cantidad' => 5, 'costo' => 20, 'isv' => false]]);
        DB::table('distribucion')->insert(['lote' => 1, 'precio' => 15, 'isv' => true, 'dto1' => 10]);
        (require base_path('database/migrations/2026_10_31_000001_distribution_promotions.php'))->up();
        (require base_path('database/migrations/2026_10_31_000002_distribution_promotion_stocks.php'))->up();
        Schema::create('stocks', function (Blueprint $t): void {
            $t->id();
            $t->string('descripcion');
        });
        DB::table('stocks')->insert(['id' => 7, 'descripcion' => 'Stock centro']);
        DB::table('distribucion')->update(['promocion_activa' => true, 'promocion_porcentaje' => 15, 'promocion_hasta' => '2026-12-31',
            'promocion_todos_stocks' => false, 'promocion_stock_ids' => '[7]', 'mostrar_encabezado' => true, 'encabezado_texto' => 'Oferta centro']);
        $catalog = app(SelectorCatalog::class);
        $definition = $catalog->definition('distribution-lots');
        $page = $catalog->page($definition, ['filters' => ['product' => 7]]);
        $this->assertSame(1, $page->total());
        $this->assertSame(10.0, $page->items()[0]['record']['costo']);
        $this->assertTrue($page->items()[0]['record']['isv']);
        $this->assertSame(15.0, $page->items()[0]['record']['distribucion']['precio']);
        $this->assertSame(10.0, $page->items()[0]['record']['distribucion']['dto1']);
        $this->assertTrue($page->items()[0]['record']['distribucion']['promocion_activa']);
        $this->assertSame('Oferta centro', $page->items()[0]['record']['distribucion']['encabezado_texto']);
        $this->assertSame([7], $page->items()[0]['record']['distribucion']['promocion_stock_ids']);
        $this->assertSame('Stock centro', $page->items()[0]['record']['distribucion']['promocion_stocks'][0]['descripcion']);
        $this->assertSame(0, $catalog->page($definition, ['filters' => ['product' => 7], 'selected' => '2'])->total());

        foreach (range(3, 15) as $id) {
            DB::table('lotes')->insert(['id' => $id, 'producto' => 7, 'lote' => 'L-'.$id, 'cantidad' => 5, 'costo' => 10, 'isv' => true]);
        }
        DB::table('distribucion')->insert(['lote' => 15, 'precio' => 15, 'isv' => true]);

        $firstPage = $catalog->page($definition, ['filters' => ['product' => 7]]);
        $this->assertSame(14, $firstPage->total());
        $this->assertSame(array_map('strval', range(14, 5)), array_column($firstPage->items(), 'id'));
        $secondPage = $catalog->page($definition, ['filters' => ['product' => 7], 'page' => 2]);
        $this->assertSame(['4', '3', '15', '1'], array_column($secondPage->items(), 'id'));
        $searched = $catalog->page($definition, ['filters' => ['product' => 7], 'search' => 'L-1']);
        $this->assertSame(['14', '13', '12', '11', '10', '15'], array_column($searched->items(), 'id'));
        $selected = $catalog->page($definition, ['filters' => ['product' => 7], 'selected' => '15']);
        $this->assertTrue($selected->items()[0]['record']['configurado']);
    }
}
