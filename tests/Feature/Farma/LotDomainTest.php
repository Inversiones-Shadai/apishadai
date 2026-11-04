<?php

namespace Tests\Feature\Farma;

use App\Http\Resources\Farma\DistributionLotResource;
use App\Http\Resources\Farma\DistributionProductResource;
use App\Http\Resources\Farma\LotProductResource;
use App\Http\Resources\Farma\LotResource;
use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use App\Repositories\Farma\DistributionRepository;
use App\Repositories\Farma\LotRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LotDomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->createSchema();
        $this->seedLots();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_it_lists_only_products_with_lots_and_aggregates_their_inventory(): void
    {
        $repository = new LotRepository(new Lote);
        $page = $repository->paginateProducts();

        $this->assertSame(2, $page->total());
        $first = LotProductResource::make($page->items()[0])->resolve(request());
        $this->assertSame(1, $first['producto_id']);
        $this->assertSame('Amoxicilina', $first['descripcion']);
        $this->assertSame('Laboratorio A', $first['laboratorio']);
        $this->assertSame('Tableta', $first['presentacion']);
        $this->assertSame('Oral', $first['administracion']);
        $this->assertTrue($first['tiene_imagen']);
        $this->assertSame(2, $first['lotes_count']);
        $this->assertSame(8, $first['cantidad_total']);
        $this->assertSame('2027-01-01', $first['proxima_expiracion']);

        $this->assertSame(1, $repository->paginateProducts(30, 'Laboratorio B')->total());
        $this->assertSame(0, $repository->paginateProducts(30, 'Producto sin lotes')->total());
    }

    public function test_product_lot_detail_includes_dates_quantity_and_purchase_invoice(): void
    {
        $lots = LotResource::collection((new LotRepository(new Lote))->productLots(1))->resolve(request());

        $this->assertCount(2, $lots);
        $this->assertSame('LOTE-A', $lots[0]['lote']);
        $this->assertSame('2026-01-01', $lots[0]['fecha_elaboracion']);
        $this->assertSame('2027-01-01', $lots[0]['fecha_expiracion']);
        $this->assertSame(3, $lots[0]['cantidad']);
        $this->assertSame(50.0, $lots[0]['costo']);
        $this->assertTrue($lots[0]['isv']);
        $this->assertSame('FAC-001', $lots[0]['factura']['nro']);
        $this->assertSame(10, $lots[0]['factura']['id']);
    }

    public function test_distribution_lists_the_same_products_and_tracks_configured_lots(): void
    {
        $repository = new DistributionRepository(new Lote, new Distribucion);
        $page = $repository->paginateProducts();
        $first = DistributionProductResource::make($page->getCollection()->firstWhere('producto', 1))->resolve(request());

        $this->assertSame(1, $first['producto_id']);
        $this->assertSame(2, $first['lotes_count']);
        $this->assertSame(1, $first['lotes_configurados']);
        $this->assertSame(8, $first['cantidad_total']);

        $lots = DistributionLotResource::collection($repository->productLots(1))->resolve(request());
        $this->assertSame('LOTE-B', $lots[0]['lote']);
        $this->assertFalse($lots[0]['configurado']);
        $this->assertTrue($lots[1]['configurado']);
        $this->assertSame(50.0, $lots[1]['costo']);
        $this->assertTrue($lots[1]['isv']);
        $this->assertSame(125.5, $lots[1]['distribucion']['precio']);
    }

    public function test_distribution_prioritizes_products_with_pending_lots_before_pagination_and_search(): void
    {
        $repository = new DistributionRepository(new Lote, new Distribucion);
        DB::table('lotes')->whereIn('id', [1, 2])->update(['created_at' => '2026-10-01 09:00:00']);
        DB::table('lotes')->where('id', 3)->update(['created_at' => '2026-10-03 09:00:00']);
        DB::table('distribucion')->insert([
            'lote' => 3, 'precio' => 30, 'isv' => false,
            'dto1' => 0, 'dto2' => 0, 'dto3' => 0, 'dto4' => 0,
        ]);

        $this->assertSame([1, 2], $repository->paginateProducts()->getCollection()->pluck('producto')->all());
        $this->assertSame([1, 2], $repository->paginateProducts(30, 'Laboratorio')->getCollection()->pluck('producto')->all());
        $this->assertSame([2], $repository->paginateProducts(30, 'Ibuprofeno')->getCollection()->pluck('producto')->all());
        $this->assertSame([1], $repository->paginateProducts(1)->getCollection()->pluck('producto')->all());
        request()->merge(['page' => 2]);
        $this->assertSame([2], $repository->paginateProducts(1)->getCollection()->pluck('producto')->all());

        request()->merge(['page' => 1]);
        $repository->saveForLot(2, ['precio' => 90, 'dto1' => 0, 'dto2' => 0, 'dto3' => 0, 'dto4' => 0]);
        $this->assertSame([2, 1], $repository->paginateProducts()->getCollection()->pluck('producto')->all());
    }

    public function test_distribution_orders_products_by_latest_lot_entry_before_pagination_and_search(): void
    {
        $repository = new DistributionRepository(new Lote, new Distribucion);
        DB::table('lotes')->whereIn('id', [1, 2])->update(['created_at' => '2026-10-01 09:00:00']);
        DB::table('lotes')->where('id', 3)->update([
            'created_at' => '2026-10-02 09:00:00',
            'updated_at' => '2026-10-13 09:00:00',
        ]);
        DB::table('lotes')->insert([
            'id' => 4, 'compra' => 11, 'producto' => 1, 'lote' => 'LOTE-NUEVO',
            'fecha_elab' => '2026-01-01', 'fecha_exp' => '2027-01-01',
            'cantidad' => 2, 'created_at' => '2026-10-03 09:00:00',
        ]);

        $this->assertSame([1, 2], $repository->paginateProducts()->getCollection()->pluck('producto')->all());
        $this->assertSame([1, 2], $repository->paginateProducts(30, 'Laboratorio')->getCollection()->pluck('producto')->all());
        $this->assertSame([2], $repository->paginateProducts(30, 'Ibuprofeno')->getCollection()->pluck('producto')->all());

        $firstPage = $repository->paginateProducts(1);
        $this->assertSame(2, $firstPage->total());
        $this->assertSame([1], $firstPage->getCollection()->pluck('producto')->all());
        request()->merge(['page' => 2]);
        $this->assertSame([2], $repository->paginateProducts(1)->getCollection()->pluck('producto')->all());
    }

    public function test_distribution_breaks_equal_entry_dates_by_latest_lot_id(): void
    {
        DB::table('lotes')->update(['created_at' => '2026-10-03 09:00:00']);
        $repository = new DistributionRepository(new Lote, new Distribucion);

        $this->assertSame([2, 1], $repository->paginateProducts()->getCollection()->pluck('producto')->all());
        DB::table('lotes')->update(['created_at' => null]);
        $this->assertSame([2, 1], $repository->paginateProducts()->getCollection()->pluck('producto')->all());
    }

    public function test_distribution_is_created_or_updated_once_per_lot(): void
    {
        $repository = new DistributionRepository(new Lote, new Distribucion);
        $payload = ['precio' => 80.25, 'isv' => true, 'dto1' => 5, 'dto2' => 0, 'dto3' => 0, 'dto4' => 0];

        $created = $repository->saveForLot(2, $payload);
        $updated = $repository->saveForLot(2, [...$payload, 'precio' => 90]);

        $this->assertNotNull($created);
        $this->assertSame($created->id, $updated?->id);
        $this->assertSame('90.00', $updated?->precio);
        $this->assertFalse($updated?->isv);
        $this->assertSame(2, DB::table('distribucion')->count());
    }

    private function createSchema(): void
    {
        Schema::create('laboratorios', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->timestamps();
        });
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratorio');
            $table->unsignedBigInteger('familia');
            $table->string('codigo');
            $table->string('descripcion');
            $table->binary('imagen')->nullable();
            $table->timestamps();
        });
        Schema::create('fam_presentacion', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('fam_administracion', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('familia', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('presentacion');
            $table->unsignedBigInteger('administracion');
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->string('nro');
            $table->timestamps();
        });
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->integer('compra')->nullable();
            $table->integer('producto');
            $table->string('lote', 100);
            $table->date('fecha_elab');
            $table->date('fecha_exp');
            $table->integer('cantidad');
            $table->decimal('costo', 10, 2)->nullable();
            $table->boolean('isv')->nullable();
            $table->timestamps();
        });
        Schema::create('distribucion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lote')->unique();
            $table->decimal('precio', 10, 2)->nullable();
            $table->boolean('isv');
            $table->decimal('dto1', 4, 2);
            $table->decimal('dto2', 4, 2);
            $table->decimal('dto3', 4, 2);
            $table->decimal('dto4', 4, 2);
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_31_000001_distribution_promotions.php'))->up();
        (require base_path('database/migrations/2026_10_31_000002_distribution_promotion_stocks.php'))->up();
    }

    private function seedLots(): void
    {
        DB::table('laboratorios')->insert([
            ['id' => 1, 'nombre' => 'Laboratorio A'],
            ['id' => 2, 'nombre' => 'Laboratorio B'],
        ]);
        DB::table('fam_presentacion')->insert(['id' => 1, 'descripcion' => 'Tableta']);
        DB::table('fam_administracion')->insert(['id' => 1, 'descripcion' => 'Oral']);
        DB::table('familia')->insert(['id' => 1, 'presentacion' => 1, 'administracion' => 1, 'descripcion' => 'Medicamento']);
        DB::table('productos')->insert([
            ['id' => 1, 'laboratorio' => 1, 'familia' => 1, 'codigo' => 'P-001', 'descripcion' => 'Amoxicilina', 'imagen' => 'image'],
            ['id' => 2, 'laboratorio' => 2, 'familia' => 1, 'codigo' => 'P-002', 'descripcion' => 'Ibuprofeno', 'imagen' => null],
            ['id' => 3, 'laboratorio' => 1, 'familia' => 1, 'codigo' => 'P-003', 'descripcion' => 'Producto sin lotes', 'imagen' => null],
        ]);
        DB::table('compras')->insert([
            ['id' => 10, 'nro' => 'FAC-001'],
            ['id' => 11, 'nro' => 'FAC-002'],
        ]);
        DB::table('lotes')->insert([
            ['id' => 1, 'compra' => 10, 'producto' => 1, 'lote' => 'LOTE-A', 'fecha_elab' => '2026-01-01', 'fecha_exp' => '2027-01-01', 'cantidad' => 3, 'costo' => 50, 'isv' => true],
            ['id' => 2, 'compra' => 11, 'producto' => 1, 'lote' => 'LOTE-B', 'fecha_elab' => '2026-02-01', 'fecha_exp' => '2028-01-01', 'cantidad' => 5, 'costo' => 60, 'isv' => false],
            ['id' => 3, 'compra' => 11, 'producto' => 2, 'lote' => 'LOTE-C', 'fecha_elab' => '2026-03-01', 'fecha_exp' => '2029-01-01', 'cantidad' => 7, 'costo' => 20, 'isv' => false],
        ]);
        DB::table('distribucion')->insert([
            'lote' => 1,
            'precio' => 125.50,
            'isv' => 1,
            'dto1' => 5,
            'dto2' => 0,
            'dto3' => 0,
            'dto4' => 0,
        ]);
    }
}
