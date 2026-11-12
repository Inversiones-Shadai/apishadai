<?php

namespace Tests\Feature;

use App\Http\Controllers\API\Farma\SaleAlertController;
use App\Services\Headquarters\PosSaleInformation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PosSaleInformationTest extends TestCase
{
    private const NORTH = '10000000-0000-4000-8000-000000000001';

    private const SOUTH = '10000000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['device_estado', 'stock_estado'] as $name) {
            Schema::create($name, fn (Blueprint $t) => [$t->id(), $t->string('descripcion')]);
            DB::table($name)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        Schema::create('stocks', fn (Blueprint $t) => [$t->id(), $t->integer('estado'), $t->string('descripcion')]);
        DB::table('stocks')->insert([['id' => 1, 'estado' => 1, 'descripcion' => 'Norte'], ['id' => 2, 'estado' => 1, 'descripcion' => 'Sur']]);
        Schema::create('devices', fn (Blueprint $t) => [$t->id(), $t->integer('stock'), $t->integer('estado'), $t->string('hq_client_uuid')]);
        DB::table('devices')->insert([
            ['id' => 1, 'stock' => 1, 'estado' => 1, 'hq_client_uuid' => self::NORTH],
            ['id' => 2, 'stock' => 2, 'estado' => 1, 'hq_client_uuid' => self::SOUTH],
        ]);
        Schema::create('productos', fn (Blueprint $t) => [$t->id(), $t->string('codigo'), $t->string('descripcion')]);
        Schema::create('prod_categorias', fn (Blueprint $t) => [$t->id(), $t->string('nombre')]);
        DB::table('productos')->insert(['id' => 3, 'codigo' => 'A3', 'descripcion' => 'Producto de prueba']);
        DB::table('prod_categorias')->insert(['id' => 4, 'nombre' => 'Controlados']);
        (require database_path('migrations/2026_11_05_000001_create_pos_sale_information.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function exchange(int $stock, array $changes = []): array
    {
        return app(PosSaleInformation::class)->exchange($stock, $stock === 1 ? self::NORTH : self::SOUTH,
            $changes + ['stock_id' => (string) $stock, 'balance_after' => '0', 'alert_after' => '0']);
    }

    private function request(array $data): Request
    {
        return Request::create('/', 'POST', $data);
    }

    private function alert(array $changes = []): array
    {
        return $changes + ['objetivo' => 'categoria', 'categoria_id' => '4', 'alcance' => 'todos',
            'titulo' => 'Venta controlada', 'requisitos' => ['Revisar receta', 'Verificar identidad'], 'activo' => true];
    }

    public function test_alert_scope_changes_remove_the_old_copy_and_deletion_retires_the_new_copy(): void
    {
        $controller = app(SaleAlertController::class);
        $response = $controller->store($this->request($this->alert()));
        $this->assertSame(201, $response->getStatusCode());
        $id = (int) $response->getData(true)['data']['id'];
        $global = $this->exchange(1)['alerts'][0];
        $this->assertSame(['Revisar receta', 'Verificar identidad'], $global['requisitos']);
        $this->assertSame('4', $global['categoria_id']);
        $this->assertSame($global, $this->exchange(2)['alerts'][0]);

        $controller->update($this->request($this->alert(['revision' => $global['revision'], 'alcance' => 'stock', 'stock_id' => '2'])), $id);
        $old = $this->exchange(1, ['alert_after' => $global['revision']])['alerts'][0];
        $this->assertSame(['id', 'revision', 'retirada'], array_keys($old));
        $this->assertTrue($old['retirada']);
        $scoped = $this->exchange(2, ['alert_after' => $global['revision']])['alerts'][0];
        $this->assertFalse($scoped['retirada']);
        $this->assertSame(2, $scoped['stock_id']);
        $controller->destroy($this->request(['revision' => $scoped['revision']]), $id);
        $this->assertTrue($this->exchange(2, ['alert_after' => $scoped['revision']])['alerts'][0]['retirada']);
        $this->assertSame([], $controller->index()->getData(true)['data']);
    }

    public function test_alert_validation_requires_one_target_and_one_stock_when_scoped(): void
    {
        $this->expectException(ValidationException::class);
        app(SaleAlertController::class)->store($this->request($this->alert(['alcance' => 'stock', 'producto_id' => '3'])));
    }

    public function test_stock_publication_is_absolute_idempotent_and_delivers_zero_to_other_branches(): void
    {
        $original = ['request_id' => (string) Str::uuid(), 'rows' => [['producto_id' => '3', 'cantidad' => '12.1250']]];
        $first = $this->exchange(1, ['upload' => $original]);
        $this->assertSame($original['request_id'], $first['accepted_request_id']);
        $this->assertSame(12.125, (float) $first['balances'][0]['cantidad']);
        $cursor = $first['balances'][0]['revision'];
        $this->assertSame([], $this->exchange(1, ['upload' => $original, 'balance_after' => $cursor])['balances']);

        $this->exchange(1, ['upload' => ['request_id' => (string) Str::uuid(), 'rows' => [['producto_id' => '3', 'cantidad' => '0']]]]);
        $this->exchange(1, ['upload' => $original]);
        $remote = $this->exchange(2, ['balance_after' => $cursor])['balances'];
        $this->assertCount(1, $remote);
        $this->assertSame(1, $remote[0]['stock_id']);
        $this->assertSame(0.0, (float) $remote[0]['cantidad']);
        $this->assertDatabaseCount('pos_branch_balance_receipts', 2);
    }

    public function test_a_device_cannot_publish_as_a_different_stock(): void
    {
        try {
            $this->exchange(1, ['stock_id' => '2']);
            $this->fail('El dispositivo no debe publicar bajo otro stock.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('pos_branch_balances', 0);
    }
}
