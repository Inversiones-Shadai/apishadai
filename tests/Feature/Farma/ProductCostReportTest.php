<?php

namespace Tests\Feature\Farma;

use App\Services\Farma\ProductCostReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductCostReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'farma.purchase_isv_rate' => 0.5]);
        DB::purge('sqlite');
        foreach (['productos', 'proveedores'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                if ($name === 'productos') {
                    $table->string('codigo');
                    $table->string('descripcion');
                } else {
                    $table->string('nombre');
                }
            });
        }
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->string('nro');
            $table->integer('proveedor');
            $table->string('estado');
            $table->timestamp('created_at');
        });
        Schema::create('compra_detalle', function (Blueprint $table): void {
            $table->id();
            $table->integer('compra');
            $table->integer('producto');
            $table->integer('cantidad');
            $table->string('lote');
            foreach (['costo', 'descuento', 'total'] as $field) {
                $table->decimal($field, 19, 2)->nullable();
            }
            $table->boolean('isv')->nullable();
            $table->boolean('es_bonificacion')->default(false);
        });
        DB::table('productos')->insert([['id' => 1, 'codigo' => 'P1', 'descripcion' => 'Producto uno'], ['id' => 2, 'codigo' => 'P2', 'descripcion' => 'Sin historial']]);
        DB::table('proveedores')->insert([['id' => 1, 'nombre' => 'Proveedor uno'], ['id' => 2, 'nombre' => 'Proveedor dos']]);
        foreach ([1 => 'pagada', 2 => 'pendiente', 3 => 'pagada', 4 => 'borrador', 5 => 'anulada', 6 => 'eliminada', 7 => 'pendiente', 8 => 'pagada'] as $id => $state) {
            DB::table('compras')->insert(['id' => $id, 'nro' => 'F-'.$id, 'estado' => $state, 'proveedor' => $id === 3 ? 2 : 1,
                'created_at' => sprintf('2026-01-%02d %s', $id, $id === 3 ? '23:59:59' : '00:00:00')]);
        }
        $this->line(1, 2, 10, true, 0, 23);
        $this->line(1, 1, 20, false, 0, 20);
        $this->line(2, 4, 15, true, 1, 63.2);
        $this->line(3, 1, 12, false, 2, 10);
        foreach ([4, 5, 6] as $purchase) {
            $this->line($purchase, 1, 999, false, 0, 999);
        }
        $this->line(7, 8, 0, false, 0, 0, true);
        $this->line(8, 1, null, null, null, null);
    }

    private function line(int $purchase, int $quantity, ?float $cost, ?bool $tax, ?float $discount, ?float $total, bool $bonus = false): void
    {
        DB::table('compra_detalle')->insert(['compra' => $purchase, 'producto' => 1, 'cantidad' => $quantity,
            'lote' => 'L-'.DB::table('compra_detalle')->count(), 'costo' => $cost, 'isv' => $tax,
            'descuento' => $discount, 'total' => $total, 'es_bonificacion' => $bonus]);
    }

    public function test_report_uses_weighted_purchase_costs_and_saved_tax_instead_of_the_current_rate(): void
    {
        $report = app(ProductCostReport::class)->report(['producto_id' => 1]);
        $rows = $report['compras'];
        $this->assertSame([1, 2, 3, 7, 8], array_column($rows, 'id'));
        $this->assertSame(13.3333, $rows[0]['costo_unitario']);
        $this->assertSame(14.3333, $rows[0]['costo_final_unitario']);
        $this->assertSame('mixto', $rows[0]['isv_estado']);
        $this->assertSame(1.0, $rows[0]['isv_unitario']);
        $this->assertSame(1.8, $rows[1]['isv_unitario']);
        $this->assertSame(15.8, $rows[1]['costo_final_unitario']);
        $this->assertSame('sube', $rows[1]['tendencia']);
        $this->assertSame(12.5, $rows[1]['variacion_pct']);
        $this->assertSame('baja', $rows[2]['tendencia']);
        $this->assertSame(-20.0, $rows[2]['variacion_pct']);
        $this->assertTrue($rows[3]['solo_bonificacion']);
        $this->assertNull($rows[3]['costo_unitario']);
        $this->assertNull($rows[4]['costo_unitario']);
        $this->assertSame('sin_dato', $rows[4]['isv_estado']);
        $this->assertSame(8, $report['resumen']['bonificaciones']);
        $this->assertSame(3, $report['resumen']['comparables']);
        $this->assertSame(12.0, $report['resumen']['costo_minimo']);
        $this->assertSame(15.0, $report['resumen']['costo_maximo']);
    }

    public function test_filters_include_end_of_day_and_keep_product_and_supplier_scope(): void
    {
        $service = app(ProductCostReport::class);
        $filters = ['producto_id' => 1, 'fecha_desde' => '2026-01-02', 'fecha_hasta' => '2026-01-03'];
        $this->assertSame([2, 3], array_column($service->report($filters)['compras'], 'id'));
        $this->assertSame([3], array_column($service->report($filters + ['proveedor_id' => 2])['compras'], 'id'));
        $empty = $service->report(['producto_id' => 2]);
        $this->assertSame([], $empty['compras']);
        $this->assertNull($empty['resumen']['costo_reciente']);
        $this->assertNull($empty['resumen']['variacion_pct']);
    }

    public function test_zero_cost_baselines_never_divide_by_zero_and_bonuses_do_not_reset_comparison(): void
    {
        DB::table('compra_detalle')->where('compra', 1)->update(['costo' => 0, 'total' => 0, 'isv' => false]);
        $rows = app(ProductCostReport::class)->report(['producto_id' => 1])['compras'];
        $this->assertSame(15.0, $rows[1]['variacion']);
        $this->assertNull($rows[1]['variacion_pct']);
        DB::table('compra_detalle')->where('compra', 8)->update(['costo' => 10, 'isv' => false, 'descuento' => 0, 'total' => 10]);
        $rows = app(ProductCostReport::class)->report(['producto_id' => 1])['compras'];
        $this->assertSame(-2.0, $rows[4]['variacion']);
    }

    public function test_api_validates_product_and_ranges(): void
    {
        $this->withoutMiddleware();
        $this->getJson('/api/farma/reporte-costeo/consultar?producto_id=1')->assertOk()->assertJsonPath('data.producto.codigo', 'P1')->assertJsonCount(5, 'data.compras');
        foreach ([[], ['producto_id' => 99], ['producto_id' => 1, 'fecha_desde' => '2026-02-31'],
            ['producto_id' => 1, 'fecha_desde' => '2026-02-01', 'fecha_hasta' => '2026-01-01']] as $filters) {
            $this->getJson('/api/farma/reporte-costeo/consultar?'.http_build_query($filters))->assertUnprocessable();
        }
    }

    public function test_large_ranges_are_rejected_instead_of_silently_truncating_the_chart(): void
    {
        $purchases = $details = [];
        foreach (range(100, 1100) as $id) {
            $purchases[] = ['id' => $id, 'nro' => 'L-'.$id, 'estado' => 'pagada', 'proveedor' => 1, 'created_at' => '2026-02-01 00:00:00'];
            $details[] = ['compra' => $id, 'producto' => 1, 'cantidad' => 1, 'lote' => 'L', 'costo' => 1, 'isv' => false, 'descuento' => 0, 'total' => 1, 'es_bonificacion' => false];
        }
        foreach (array_chunk($purchases, 100) as $chunk) {
            DB::table('compras')->insert($chunk);
        }
        foreach (array_chunk($details, 100) as $chunk) {
            DB::table('compra_detalle')->insert($chunk);
        }
        $this->expectException(ValidationException::class);
        app(ProductCostReport::class)->report(['producto_id' => 1]);
    }
}
