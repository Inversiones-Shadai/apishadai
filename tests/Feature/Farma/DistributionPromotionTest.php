<?php

namespace Tests\Feature\Farma;

use App\Http\Requests\Farma\Distribucion\DistributionRequest;
use App\Http\Resources\Farma\DistributionLotResource;
use App\Http\Resources\Farma\DistributionResource;
use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use App\Repositories\Farma\DistributionRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DistributionPromotionTest extends TestCase
{
    public function test_active_promotion_requires_a_valid_percentage_and_end_date(): void
    {
        $rules = (new DistributionRequest)->rules();
        $base = ['precio' => 100, 'dto1' => 5, 'dto2' => 20, 'dto3' => 30, 'dto4' => 0, 'promocion_activa' => true];
        $invalid = Validator::make($base, $rules);
        $this->assertTrue($invalid->fails());
        $this->assertArrayHasKey('promocion_hasta', $invalid->errors()->toArray());
        $this->assertArrayHasKey('promocion_porcentaje', $invalid->errors()->toArray());
        $valid = $base + ['promocion_porcentaje' => 15, 'promocion_hasta' => '2026-10-31', 'mostrar_encabezado' => true, 'encabezado_texto' => 'Oferta'];
        $this->assertFalse(Validator::make($valid, $rules)->fails());
        foreach ([0, -1, 100] as $percentage) {
            $this->assertTrue(Validator::make(array_replace($valid, ['promocion_porcentaje' => $percentage]), $rules)->fails());
        }
        $this->assertTrue(Validator::make(array_replace($valid, ['encabezado_texto' => str_repeat('a', 161)]), $rules)->fails());
        $this->assertTrue(Validator::make(array_replace($valid, ['promocion_hasta' => '2026-02-31']), $rules)->fails());
    }

    public function test_resource_preserves_one_header_per_lot_and_optional_promotion(): void
    {
        $model = new Distribucion(['lote' => 8, 'precio' => 100, 'dto1' => 5, 'dto2' => 20, 'dto3' => 30, 'dto4' => 0,
            'promocion_activa' => true, 'promocion_porcentaje' => 15, 'promocion_hasta' => '2026-10-31',
            'mostrar_encabezado' => true, 'encabezado_texto' => 'Oferta del lote']);
        $data = (new DistributionResource($model))->toArray(new Request);
        $this->assertTrue($data['promocion_activa']);
        $this->assertSame(15.0, $data['promocion_porcentaje']);
        $this->assertSame('2026-10-31', $data['promocion_hasta']);
        $this->assertSame('Oferta del lote', $data['encabezado_texto']);
        $this->assertSame(20.0, $data['dto2']);
        $lot = (new Lote(['lote' => 'TEST']))->setRelation('distribucionDetalle', $model)->setRelation('compraDetalle', null);
        $loaded = (new DistributionLotResource($lot))->toArray(new Request);
        $this->assertSame($data, $loaded['distribucion']);
    }

    public function test_targeted_promotion_requires_existing_stocks_and_preserves_selected_names(): void
    {
        Schema::create('stocks', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
        });
        DB::table('stocks')->insert([['id' => 1, 'descripcion' => 'Stock centro'], ['id' => 2, 'descripcion' => 'Stock norte']]);
        $rules = (new DistributionRequest)->rules();
        $data = ['precio' => 100, 'dto1' => 5, 'dto2' => 20, 'dto3' => 30, 'dto4' => 0,
            'promocion_todos_stocks' => false, 'promocion_stock_ids' => [1, 2]];
        $this->assertFalse(Validator::make($data, $rules)->fails());
        foreach ([[], [99], [1, 1], [-1], ['x']] as $ids) {
            $this->assertTrue(Validator::make(array_replace($data, ['promocion_stock_ids' => $ids]), $rules)->fails());
        }
        $this->assertFalse(Validator::make(array_replace($data, ['promocion_todos_stocks' => true, 'promocion_stock_ids' => null]), $rules)->fails());
        $resource = (new DistributionResource(new Distribucion($data)))->toArray(new Request);
        $this->assertFalse($resource['promocion_todos_stocks']);
        $this->assertSame([1, 2], $resource['promocion_stock_ids']);
        $this->assertSame(['Stock centro', 'Stock norte'], array_column($resource['promocion_stocks'], 'descripcion'));
    }

    public function test_saving_prices_preserves_the_lot_promotion_and_its_stock_scope(): void
    {
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->integer('compra')->nullable();
            $table->boolean('isv');
        });
        Schema::create('distribucion', function (Blueprint $table): void {
            $table->id();
            $table->integer('lote')->unique();
            $table->decimal('precio');
            $table->boolean('isv');
            foreach (range(1, 4) as $type) {
                $table->decimal('dto'.$type)->default(0);
            }
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_31_000001_distribution_promotions.php'))->up();
        (require base_path('database/migrations/2026_10_31_000002_distribution_promotion_stocks.php'))->up();
        DB::table('lotes')->insert(['id' => 8, 'isv' => true]);
        $repository = app(DistributionRepository::class);
        $repository->saveForLot(8, ['precio' => 100, 'promocion_activa' => true, 'promocion_porcentaje' => 15,
            'promocion_hasta' => '2026-12-31', 'promocion_todos_stocks' => false, 'promocion_stock_ids' => [7],
            'mostrar_encabezado' => true, 'encabezado_texto' => 'Oferta centro']);
        $saved = $repository->saveForLot(8, ['precio' => 120]);
        $this->assertTrue($saved->promocion_activa);
        $this->assertFalse($saved->promocion_todos_stocks);
        $this->assertSame([7], $saved->promocion_stock_ids);
        $this->assertSame('Oferta centro', $saved->encabezado_texto);
        $this->assertSame('120.00', $saved->precio);
    }
}
