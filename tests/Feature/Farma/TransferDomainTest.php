<?php

namespace Tests\Feature\Farma;

use App\Http\Controllers\API\Farma\TransferController;
use App\Http\Middleware\EnsureTransferConversionPermission;
use App\Http\Requests\Farma\Transferencias\TransferSendRequest;
use App\Http\Resources\Farma\TransferResource;
use App\Interfaces\Config\DeviceInfoService;
use App\Models\Device;
use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use App\Models\Farma\Transferencia;
use App\Models\SyncChange;
use App\Models\User;
use App\Repositories\Farma\TransferRepository;
use App\Services\Farma\TransferProductConversion;
use App\Services\Farma\TransferStockScope;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TransferDomainTest extends TestCase
{
    private function prepareConversion(): array
    {
        (require database_path('migrations/2026_10_11_000006_product_parent.php'))->up();
        (require database_path('migrations/2026_10_24_000002_transfer_product_conversions.php'))->up();
        Schema::table('lotes', fn (Blueprint $table) => $table->boolean('es_bonificacion')->default(false));
        Schema::create('distribucion', function (Blueprint $table): void {
            $table->id();
            $table->integer('lote');
            $table->decimal('precio', 10, 2);
            $table->boolean('isv');
            foreach (['dto1', 'dto2', 'dto3', 'dto4'] as $field) {
                $table->decimal($field)->default(0);
            }
            $table->timestamps();
        });
        DB::table('prod_unidades')->insert(['id' => 2, 'abreviatura_c' => 'BL', 'cantidad_c' => 10, 'abreviatura_v' => 'UND', 'cantidad_v' => 2]);
        DB::table('productos')->insert(['id' => 2, 'codigo' => 'HIJO', 'descripcion' => 'Producto hijo', 'producto_padre_id' => 1, 'unidad' => 2]);
        DB::table('lotes')->where('id', 1)->update(['costo' => 120]);
        DB::table('distribucion')->insert(['lote' => 1, 'precio' => 200, 'isv' => true, 'dto1' => 3, 'dto2' => 5, 'dto3' => 7, 'dto4' => 9]);

        return ['request_id' => (string) Str::uuid(), 'lote_padre' => 1, 'producto_hijo' => 2, 'cantidad' => 2];
    }

    public function test_conversion_permission_requires_send_and_an_active_individual_grant(): void
    {
        $this->seedStockFilterData(false);
        foreach (['modulo_estados', 'vista_estados'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('descripcion');
            });
            DB::table($tableName)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        foreach (['modulos', 'vistas'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->integer('estado')->default(1));
        }
        DB::table('actionsvistas')->insert(['id' => 4, 'vista' => 1, 'codigo' => 'trfbtn04']);
        $request = $this->stockFilterRequest();
        $guard = new EnsureTransferConversionPermission;
        $next = fn () => response()->json(['ok' => true]);
        foreach (['trfbtn01', 'trfbtn04'] as $code) {
            $request->headers->set('Action-Btn', $code);
            try {
                $guard->handle($request, $next);
                $this->fail('No debe convertir con permisos de consulta.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        DB::table('permisos')->insert(['usuario' => 1, 'modulo' => 1, 'vista' => 1, 'actionvista' => 4]);
        $this->assertSame(200, $guard->handle($request, $next)->getStatusCode());
        DB::table('permisos')->where('actionvista', 4)->update(['lifetime' => now()->subMinute()]);
        $this->expectException(HttpException::class);
        $guard->handle($request, $next);
    }

    public function test_conversion_uses_parent_purchase_units_and_preserves_lot_and_commercial_values(): void
    {
        $data = $this->prepareConversion();
        $service = new TransferProductConversion;
        $options = $service->options(1);
        $this->assertSame(100, $options['unidades_por_padre']);
        $this->assertSame(2, $options['hijos'][0]['id']);
        $result = $service->convert(1, 1, $data);
        $this->assertSame(200, $result['unidades_generadas']);
        $this->assertSame(8, (int) Lote::findOrFail(1)->cantidad);
        $child = Lote::findOrFail($result['lote_hijo']['id']);
        $this->assertSame(2, $child->producto);
        $this->assertSame('L-001', $child->lote);
        $this->assertSame('2028-01-01', $child->fecha_exp->toDateString());
        $this->assertSame('2026-01-01', $child->fecha_elab->toDateString());
        $this->assertSame('1.2000', $child->costo);
        $this->assertTrue($child->isv);
        $this->assertSame(1, $child->compra);
        $distribution = Distribucion::where('lote', $child->id)->firstOrFail();
        $this->assertSame('4.00', $distribution->precio);
        $this->assertSame('7.00', $distribution->dto3);
        $this->assertSame('9.00', $distribution->dto4);
        $this->assertTrue($distribution->isv);
        $this->assertSame($result, $service->convert(1, 1, $data));
        $this->assertDatabaseCount('lotes', 2);
        $this->assertDatabaseCount('transferencia_conversiones', 1);
        $this->assertSame(8, (int) Lote::findOrFail(1)->cantidad);
        $transfer = $this->repository()->send(1, 1, ['tipo' => 1, 'stock_para' => 2, 'detalles' => [['lote' => $child->id, 'cantidad' => 200]]]);
        $this->assertSame(200, $transfer->items);
        $this->assertSame('240.00', $transfer->subtotal);
    }

    public function test_conversion_rejects_changed_requests_stock_and_user_without_reapplying(): void
    {
        $data = $this->prepareConversion();
        $service = new TransferProductConversion;
        $service->convert(1, 1, $data);
        foreach ([[2, 1, $data], [1, 2, $data], [1, 1, [...$data, 'cantidad' => 3]]] as [$stock, $user, $payload]) {
            try {
                $service->convert($stock, $user, $payload);
                $this->fail('Debe rechazar una solicitud modificada.');
            } catch (DomainException) {
                $this->assertSame(8, (int) Lote::findOrFail(1)->cantidad);
                $this->assertDatabaseCount('lotes', 2);
            }
        }
    }

    public function test_invalid_conversions_leave_stock_unchanged(): void
    {
        $data = $this->prepareConversion();
        $service = new TransferProductConversion;
        foreach ([['cantidad' => 11], ['producto_hijo' => 1], ['cantidad' => 0]] as $changes) {
            try {
                $service->convert(1, 1, [...$data, ...$changes]);
                $this->fail('Debe rechazar la conversión.');
            } catch (DomainException) {
                $this->assertSame(10, (int) Lote::findOrFail(1)->cantidad);
                $this->assertDatabaseCount('lotes', 1);
            }
        }
        DB::table('lotes')->where('id', 1)->update(['fecha_exp' => '2020-01-01']);
        $this->expectException(DomainException::class);
        $service->convert(1, 1, $data);
    }

    public function test_conversion_rolls_back_all_writes_if_recording_fails(): void
    {
        $data = $this->prepareConversion();
        DB::unprepared("CREATE TRIGGER reject_conversion BEFORE INSERT ON transferencia_conversiones BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
        try {
            (new TransferProductConversion)->convert(1, 1, $data);
            $this->fail('Debe fallar el registro.');
        } catch (QueryException) {
            $this->assertSame(10, (int) Lote::findOrFail(1)->cantidad);
            $this->assertDatabaseCount('lotes', 1);
            $this->assertDatabaseCount('distribucion', 1);
            $this->assertDatabaseCount('transferencia_conversiones', 0);
        }
    }

    private function enableHeadquarters(): void
    {
        Schema::create('distribucion', function (Blueprint $table): void {
            $table->id();
            $table->integer('lote');
            $table->decimal('precio');
            $table->boolean('isv');
            foreach (['dto1', 'dto2', 'dto3', 'dto4'] as $field) {
                $table->decimal($field)->default(0);
            }
            $table->timestamps();
        });
        Schema::create('principal_activos', fn (Blueprint $table) => $table->id());
        Schema::create('prod_activo', function (Blueprint $table): void {
            $table->id();
            $table->integer('producto');
            $table->integer('pactivo');
            $table->timestamps();
        });
        DB::table('stocks')->insert(['id' => 3, 'descripcion' => 'Tercer stock', 'telefono' => '3', 'ubicacion' => 'C', 'estado' => 1]);
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);
    }

    public function test_headquarters_captures_one_complete_transfer_and_receipt_for_both_participant_stocks(): void
    {
        $this->enableHeadquarters();
        $transfer = $this->repository()->send(1, 1, ['tipo' => 1, 'stock_para' => 2, 'detalles' => [['lote' => 1, 'cantidad' => 2]]]);
        $change = SyncChange::where('entity', 'transferencias')->firstOrFail();
        $this->assertSame([1, 2], $change->stock_ids);
        $this->assertSame([], $change->removed_stock_ids);
        $this->assertSame(2, $change->payload['detalles'][0]['cantidad']);
        $this->assertSame(1, $change->payload['detalles'][0]['lote_datos']['producto']);
        $this->assertSame(1, SyncChange::where('entity', 'transferencias')->count());
        $this->repository()->receive($transfer->id, 2, 2);
        $received = SyncChange::orderByDesc('id')->firstOrFail();
        $this->assertSame(2, $received->version);
        $this->assertSame(2, $received->payload['usuario_recibe']);
        $this->assertNotNull($received->payload['recibido_at']);
    }

    public function test_transfer_scope_changes_preserve_removals_and_deletes_preserve_the_audience(): void
    {
        $this->enableHeadquarters();
        $transfer = $this->repository()->send(1, 1, ['tipo' => 1, 'stock_para' => 2, 'detalles' => [['lote' => 1, 'cantidad' => 2]]]);
        $transfer->updateQuietly(['stock_para' => 3]);
        $change = SyncChange::orderByDesc('id')->firstOrFail();
        $this->assertSame([1, 3], $change->stock_ids);
        $this->assertSame([2], $change->removed_stock_ids);
        $transfer->delete();
        $deleted = SyncChange::orderByDesc('id')->firstOrFail();
        $this->assertSame('DELETE', $deleted->action);
        $this->assertSame([1, 3], $deleted->stock_ids);
        $this->assertNull($deleted->payload);
    }

    public function test_distribution_changes_include_product_lot_data_and_follow_lot_edits(): void
    {
        $this->enableHeadquarters();
        $distribution = Distribucion::create(['lote' => 1, 'precio' => 20, 'isv' => true]);
        $change = SyncChange::where('entity', 'distribucion')->firstOrFail();
        $this->assertSame('distribucion', $change->entity);
        $this->assertSame(1, $change->payload['lote_datos']['producto']);
        $this->assertSame('20.00', $change->payload['precio']);
        $this->repository()->send(1, 1, ['tipo' => 1, 'stock_para' => 2, 'detalles' => [['lote' => 1, 'cantidad' => 2]]]);
        Lote::findOrFail(1)->update(['lote' => 'LOTE-MODIFICADO']);
        $this->assertSame('LOTE-MODIFICADO', SyncChange::where('entity', 'distribucion')->orderByDesc('id')->firstOrFail()->payload['lote_datos']['lote']);
        $this->assertSame('LOTE-MODIFICADO', SyncChange::where('entity', 'transferencias')->orderByDesc('id')->firstOrFail()->payload['detalles'][0]['lote_datos']['lote']);
        $distribution->delete();
        $this->assertSame('DELETE', SyncChange::orderByDesc('id')->firstOrFail()->action);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->createSchema();
        $this->seedData();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_it_sends_a_transfer_with_server_calculated_totals_and_audit_data(): void
    {
        $transfer = $this->repository()->send(1, 1, [
            'tipo' => 1,
            'stock_para' => 2,
            'detalles' => [['lote' => 1, 'cantidad' => 2]],
        ]);

        $this->assertSame(1, $transfer->stock_de);
        $this->assertSame(2, $transfer->stock_para);
        $this->assertSame(1, $transfer->usuario_envia);
        $this->assertNull($transfer->usuario_recibe);
        $this->assertSame(2, $transfer->items);
        $this->assertSame('20.00', $transfer->subtotal);
        $this->assertSame('3.00', $transfer->isvtotal);
        $this->assertSame('23.00', $transfer->total);
        $this->assertSame('ENVIADA', $transfer->estadoDetalle->descripcion);
        $this->assertSame('PENDIENTE', $transfer->estadoRecepcionDetalle->descripcion);
        $this->assertNotNull($transfer->enviado_at);
        $this->assertNull($transfer->recibido_at);
        $this->assertDatabaseHas('trasferencia_detalle', [
            'transferencia_id' => $transfer->id,
            'lote' => 1,
            'cantidad' => 2,
            'subtotal' => 20,
            'isv' => 3,
            'total' => 23,
        ]);
        $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 8]);
        $this->assertSame('8.0000', $transfer->detalles->first()->loteDetalle->cantidad);
    }

    public function test_only_another_user_at_the_destination_can_receive_it_once(): void
    {
        $transfer = $this->createTransfer();

        foreach ([[1, 1], [7, 2]] as [$stockId, $userId]) {
            try {
                $this->repository()->receive($transfer->id, $stockId, $userId);
                $this->fail('La API permitió una recepción no autorizada.');
            } catch (DomainException) {
                $this->assertDatabaseMissing('transferencias', ['id' => $transfer->id, 'usuario_recibe' => $userId]);
            }
        }

        $received = $this->repository()->receive($transfer->id, 2, 2);

        $this->assertSame(2, $received?->usuario_recibe);
        $this->assertSame('ENVIADA', $received?->estadoDetalle->descripcion);
        $this->assertSame('RECIBIDA', $received?->estadoRecepcionDetalle->descripcion);
        $this->assertNotNull($received?->recibido_at);
        $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 9]);

        $this->expectException(DomainException::class);
        $this->repository()->receive($transfer->id, 2, 2);
    }

    public function test_list_is_scoped_to_the_current_stock_and_direction(): void
    {
        $transfer = $this->createTransfer();

        $this->assertSame(1, $this->repository()->paginate(1)->total());
        $this->assertSame(1, $this->repository()->paginate(2, 30, ['direccion' => 'recibidas'])->total());
        $this->assertSame(1, $this->repository()->paginate(2, 30, ['estado_recepcion' => 2])->total());
        $this->assertSame(0, $this->repository()->paginate(2, 30, ['direccion' => 'enviadas'])->total());
        $this->assertSame(0, $this->repository()->paginate(7)->total());

        $request = Request::create('/api/farma/transferencias/listar');
        $request->attributes->set('authenticated_device', (new Device)->forceFill(['stock' => 2]));
        $request->setUserResolver(fn (): User => User::query()->findOrFail(2));
        $payload = TransferResource::make($this->repository()->findForStock($transfer->id, 2))->resolve($request);

        $this->assertSame('enviada', $payload['estado']);
        $this->assertSame('pendiente', $payload['estado_recepcion']);
        $this->assertSame('pendiente', $payload['estado_general']);
        $this->assertTrue($payload['puede_recibir']);
        $this->assertSame('Emisor', $payload['usuario_envia']['nombre']);
        $this->assertSame('Destino', $payload['stock_para']['descripcion']);
    }

    public function test_date_range_includes_both_complete_days_and_combines_with_stock_direction_and_search(): void
    {
        $transfer = $this->createTransfer();
        DB::table('transferencias')->where('id', $transfer->id)->update(['enviado_at' => '2026-10-30 23:59:59']);
        foreach ([50 => '2026-10-31 00:00:00', 51 => '2026-11-01 23:59:59', 52 => '2026-11-02 00:00:00'] as $id => $sentAt) {
            DB::table('transferencias')->insert(array_replace($transfer->getAttributes(), ['id' => $id, 'enviado_at' => $sentAt]));
        }
        $filters = ['fecha_desde' => '2026-10-31', 'fecha_hasta' => '2026-11-01', 'direccion' => 'enviadas', 'tipo' => 1, 'search' => 'Destino'];
        $page = $this->repository()->paginate(1, 1, $filters);
        $this->assertSame(2, $page->total());
        $this->assertSame(51, $page->items()[0]->id);
        $this->assertSame(0, $this->repository()->paginate(2, 30, $filters)->total());
        $this->assertSame(1, $this->repository()->paginate(1, 30, ['fecha_desde' => '2026-10-31', 'fecha_hasta' => '2026-10-31'])->total());
        $this->assertSame(4, $this->repository()->paginate(1)->total());
    }

    public function test_transfer_api_accepts_date_ranges_and_rejects_reversed_or_invalid_dates(): void
    {
        $transfer = $this->createTransfer();
        DB::table('transferencias')->where('id', $transfer->id)->update(['enviado_at' => '2026-10-31 23:59:59']);
        $deviceInfo = \Mockery::mock(DeviceInfoService::class);
        $deviceInfo->shouldReceive('stockId')->andReturn(1);
        $controller = new TransferController($this->repository(), $deviceInfo);
        foreach ([['fecha_desde' => '2026-10-31', 'fecha_hasta' => '2026-10-31'], ['fecha_desde' => '2026-11-01', 'fecha_hasta' => '2026-11-02']] as $index => $filters) {
            $request = Request::create('/api/farma/transferencias/listar', 'GET', $filters);
            $this->app->instance('request', $request);
            $this->assertSame($index === 0 ? 1 : 0, $controller->index($request)->getData(true)['meta']['total']);
        }
        foreach ([['fecha_desde' => '2026-11-01', 'fecha_hasta' => '2026-10-31'], ['fecha_desde' => '2026-02-31']] as $filters) {
            $request = Request::create('/api/farma/transferencias/listar', 'GET', $filters);
            try {
                $controller->index($request);
                $this->fail('El rango inválido no debe consultar transferencias.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_default_stock_scope_excludes_unrelated_transfers_even_for_a_privileged_user(): void
    {
        $this->seedStockFilterData(true);
        $transfer = $this->createTransfer();
        $foreign = $transfer->getAttributes();
        $foreign['id'] = 50;
        $foreign['stock_de'] = 2;
        $foreign['stock_para'] = 3;
        DB::table('transferencias')->insert($foreign);
        $incoming = $foreign;
        $incoming['id'] = 51;
        $incoming['stock_para'] = 1;
        DB::table('transferencias')->insert($incoming);

        foreach (['todas' => [$transfer->id, 51], 'enviadas' => [$transfer->id], 'recibidas' => [51]] as $direction => $expected) {
            $request = $this->stockFilterRequest(['direccion' => $direction]);
            $payload = $this->stockFilterController()->index($request)->getData(true);
            $actual = array_column($payload['data'], 'id');
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual);
            $this->assertSame(count($expected), $payload['meta']['total']);
        }
    }

    public function test_authorized_stock_filter_uses_selected_stock_for_list_and_detail_only(): void
    {
        $this->seedStockFilterData(true);
        $transfer = $this->createTransfer();
        $foreign = $transfer->getAttributes();
        $foreign['id'] = 50;
        $foreign['stock_de'] = 2;
        $foreign['stock_para'] = 3;
        DB::table('transferencias')->insert($foreign);
        $request = $this->stockFilterRequest(['stock_id' => 2, 'direccion' => 'enviadas']);
        $payload = $this->stockFilterController()->index($request)->getData(true);

        $this->assertSame([50], array_column($payload['data'], 'id'));
        $this->assertFalse($payload['data'][0]['puede_recibir']);
        $this->assertFalse($payload['data'][0]['puede_imprimir']);
        $this->assertSame([2, 1, 3], array_column($payload['meta']['stocks'], 'id'));
        $this->assertSame(['id', 'descripcion'], array_keys($payload['meta']['stocks'][0]));
        $this->assertSame(11, $request->attributes->get('authenticated_device')->id);
        $this->assertSame(200, $this->stockFilterController()->show($request, 50)->getStatusCode());
        $this->assertSame(404, $this->stockFilterController()->show($this->stockFilterRequest(), 50)->getStatusCode());

        $incoming = $this->stockFilterController()->index($this->stockFilterRequest(['stock_id' => 2, 'direccion' => 'recibidas']))->getData(true);
        $this->assertSame([$transfer->id], array_column($incoming['data'], 'id'));
    }

    public function test_stock_filter_cannot_be_requested_with_only_list_permission_or_an_expired_permission(): void
    {
        $this->seedStockFilterData(false);
        $transfer = $this->createTransfer();
        $controller = $this->stockFilterController();
        $default = $controller->index($this->stockFilterRequest())->getData(true);
        $this->assertArrayNotHasKey('stocks', $default['meta']);

        foreach (['index', 'show'] as $method) {
            try {
                $request = $this->stockFilterRequest(['stock_id' => 2]);
                $method === 'index' ? $controller->index($request) : $controller->show($request, $transfer->id);
                $this->fail('Se permitió consultar otro stock sin trfbtn07.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        DB::table('permisos')->insert(['usuario' => 1, 'modulo' => 1, 'vista' => 1, 'actionvista' => 7, 'lifetime' => now()->subMinute()]);
        $this->assertFalse(app(TransferStockScope::class)->canFilter($this->stockFilterRequest()));
        DB::table('permisos')->where('actionvista', 7)->update(['lifetime' => now()->addMinute()]);
        $this->assertTrue(app(TransferStockScope::class)->canFilter($this->stockFilterRequest()));
        DB::table('permisos')->where('actionvista', 7)->update(['usuario' => 2]);
        $this->assertFalse(app(TransferStockScope::class)->canFilter($this->stockFilterRequest()));
    }

    public function test_stock_filter_rejects_inactive_and_missing_stocks(): void
    {
        $this->seedStockFilterData(true);

        foreach ([7, 999] as $stockId) {
            try {
                app(TransferStockScope::class)->stockId($this->stockFilterRequest(['stock_id' => $stockId]), 1);
                $this->fail('Se aceptó un stock no disponible.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
    }

    public function test_stock_filter_migration_registers_the_action_without_granting_it(): void
    {
        $this->seedStockFilterData(false);
        DB::table('actionsvistas')->where('id', 7)->delete();
        $migration = require database_path('migrations/2026_10_01_000020_add_transfer_device_filter_action.php');
        $migration->up();
        $migration->up();
        $rename = require database_path('migrations/2026_10_01_000021_rename_transfer_filter_action_to_stock.php');
        $actionId = DB::table('actionsvistas')->where('codigo', 'trfbtn07')->value('id');
        $rename->up();
        $rename->up();
        $this->assertSame(1, DB::table('actionsvistas')->where('codigo', 'trfbtn07')->count());
        $this->assertSame($actionId, DB::table('actionsvistas')->where('codigo', 'trfbtn07')->value('id'));
        $this->assertSame('filtrar por stock', DB::table('actionsvistas')->where('codigo', 'trfbtn07')->value('nombre'));
        $this->assertFalse(app(TransferStockScope::class)->canFilter($this->stockFilterRequest()));
    }

    private function stockFilterController(): TransferController
    {
        $deviceInfo = \Mockery::mock(DeviceInfoService::class);
        $deviceInfo->shouldReceive('stockId')->andReturn(1);

        return new TransferController($this->repository(), $deviceInfo);
    }

    private function stockFilterRequest(array $query = []): Request
    {
        $request = Request::create('/api/farma/transferencias/listar', 'GET', $query);
        $request->headers->set('Action-Btn', 'trfbtn01');
        $request->attributes->set('authenticated_device', (new Device)->forceFill(['id' => 11, 'stock' => 1]));
        $this->app->instance('request', $request);
        $request->setUserResolver(fn (): User => User::query()->findOrFail(1));

        return $request;
    }

    private function seedStockFilterData(bool $grant): void
    {
        Schema::create('modulos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
        });
        Schema::create('vistas', function (Blueprint $table): void {
            $table->id();
            $table->integer('modulo');
            $table->string('codigo');
        });
        Schema::create('actionsvistas', function (Blueprint $table): void {
            $table->id();
            $table->integer('vista');
            $table->string('codigo');
            $table->string('nombre')->nullable();
            $table->timestamps();
        });
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            foreach (['usuario', 'modulo', 'vista', 'actionvista'] as $column) {
                $table->integer($column);
            }
            $table->timestamp('lifetime')->nullable();
        });
        DB::table('stocks')->insert(['id' => 3, 'descripcion' => 'Otro stock', 'telefono' => '3', 'ubicacion' => 'C', 'estado' => 1]);
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm']);
        DB::table('vistas')->insert(['id' => 1, 'modulo' => 1, 'codigo' => 'trf']);
        DB::table('actionsvistas')->insert([['id' => 1, 'vista' => 1, 'codigo' => 'trfbtn01'], ['id' => 7, 'vista' => 1, 'codigo' => 'trfbtn07']]);
        DB::table('permisos')->insert(['usuario' => 1, 'modulo' => 1, 'vista' => 1, 'actionvista' => 1]);
        if ($grant) {
            DB::table('permisos')->insert(['usuario' => 1, 'modulo' => 1, 'vista' => 1, 'actionvista' => 7]);
        }
    }

    public function test_request_rejects_duplicate_lots_and_options_exclude_the_current_stock(): void
    {
        $request = TransferSendRequest::create('/api/farma/transferencias/enviar', 'POST', [
            'tipo' => 1,
            'stock_para' => 2,
            'detalles' => [
                ['lote' => 1, 'cantidad' => 1],
                ['lote' => 1, 'cantidad' => 1],
            ],
        ]);
        $validator = Validator::make($request->all(), $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('detalles.0.lote', $validator->errors()->toArray());
        $this->assertSame([2], $this->repository()->options(1)['stocks_destino']->pluck('id')->all());
        $this->assertSame(['PENDIENTE', 'RECIBIDA'], $this->repository()->options(1)['estados_recepcion']->pluck('descripcion')->all());
    }

    public function test_list_filters_by_type_and_returns_all_filter_types_even_without_results(): void
    {
        $this->createTransfer();
        DB::table('transferencias_tipo')->insert(['id' => 2, 'descripcion' => 'DEVOLUCIÓN']);
        $second = $this->repository()->send(1, 1, [
            'tipo' => 2, 'stock_para' => 2, 'detalles' => [['lote' => 1, 'cantidad' => 1]],
        ]);
        $deviceInfo = \Mockery::mock(DeviceInfoService::class);
        $deviceInfo->shouldReceive('stockId')->andReturn(1);
        $controller = new TransferController($this->repository(), $deviceInfo);
        foreach ([['direccion' => 'enviadas'], ['direccion' => 'recibidas'], ['search' => 'Sin coincidencias']] as $index => $filters) {
            $request = Request::create('/api/farma/transferencias/listar', 'GET', ['tipo' => 2] + $filters);
            $this->app->instance('request', $request);
            $payload = $controller->index($request)->getData(true);
            $this->assertSame($index === 0 ? 1 : 0, $payload['meta']['total']);
            $this->assertSame([2, 1], array_column($payload['meta']['tipos'], 'id'));
            $this->assertSame(['DEVOLUCIÓN', 'TRASLADO ENTRE STOCKS'], array_column($payload['meta']['tipos'], 'descripcion'));
            if ($index === 0) {
                $this->assertSame($second->id, $payload['data'][0]['id']);
                $this->assertSame(2, $payload['data'][0]['tipo_id']);
            }
        }
    }

    public function test_printing_is_only_allowed_for_sent_transfers_from_the_current_stock(): void
    {
        $transfer = $this->createTransfer();
        $request = Request::create('/api/farma/transferencias/buscar/'.$transfer->id);
        foreach ([1 => true, 2 => false, 7 => false] as $stock => $allowed) {
            $request->attributes->set('authenticated_device', (new Device)->forceFill(['stock' => $stock]));
            $payload = TransferResource::make($transfer)->resolve($request);
            $this->assertSame($allowed, $payload['puede_imprimir']);
        }
        $request->attributes->remove('authenticated_device');
        $this->assertFalse(TransferResource::make($transfer)->resolve($request)['puede_imprimir']);
        $request->attributes->set('authenticated_device', (new Device)->forceFill(['stock' => 1]));
        $received = $this->repository()->receive($transfer->id, 2, 2);
        $this->assertTrue(TransferResource::make($received)->resolve($request)['puede_imprimir']);
        $received->setRelation('estadoDetalle', null);
        $this->assertFalse(TransferResource::make($received)->resolve($request)['puede_imprimir']);
    }

    public function test_detail_exposes_product_and_lot_metadata_without_changing_saved_totals(): void
    {
        $transfer = $this->createTransfer();
        DB::table('lotes')->where('id', 1)->update(['costo' => 999]);
        $request = Request::create('/api/farma/transferencias/buscar/'.$transfer->id);
        $payload = TransferResource::make($this->repository()->findForStock($transfer->id, 1))->response($request)->getData(true)['data'];
        $lot = $payload['detalles'][0]['lote'];
        $this->assertSame('7701234567890', $lot['producto']['codigobar']);
        $this->assertSame('Laboratorio de prueba', $lot['producto']['laboratorio']);
        $this->assertSame('Medicamentos', $lot['producto']['categoria']);
        $this->assertSame('Tableta', $lot['producto']['presentacion']);
        $this->assertSame('Oral', $lot['producto']['administracion']);
        $this->assertSame('Analgésico', $lot['producto']['familia']);
        $this->assertSame('UND (1)', $lot['producto']['unidad']);
        $this->assertSame('2026-01-01', $lot['fecha_elaboracion']);
        $this->assertSame('2028-01-01', $lot['fecha_expiracion']);
        $this->assertSame('FAC-100', $lot['factura']);
        $this->assertEquals(10, $payload['detalles'][0]['subtotal']);
        $this->assertEquals(11.50, $payload['total']);
        $this->assertSame('A', $payload['stock_de']['ubicacion']);
        $this->assertSame('2', $payload['stock_para']['telefono']);
    }

    public function test_multiple_lots_are_deducted_by_their_sent_quantities(): void
    {
        DB::table('lotes')->insert([
            'id' => 2, 'producto' => 1, 'lote' => 'L-002',
            'fecha_elab' => '2026-01-01', 'fecha_exp' => '2028-01-01',
            'cantidad' => 5, 'costo' => 20, 'isv' => false,
        ]);

        $transfer = $this->repository()->send(1, 1, [
            'tipo' => 1, 'stock_para' => 2,
            'detalles' => [['lote' => 1, 'cantidad' => 3], ['lote' => 2, 'cantidad' => 2]],
        ]);

        $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 7]);
        $this->assertDatabaseHas('lotes', ['id' => 2, 'cantidad' => 3]);
        $this->assertSame(5, $transfer->items);
        $this->assertSame('74.50', $transfer->total);
    }

    public function test_an_exhausted_lot_cannot_be_sent_again(): void
    {
        $this->repository()->send(1, 1, [
            'tipo' => 1, 'stock_para' => 2, 'detalles' => [['lote' => 1, 'cantidad' => 10]],
        ]);
        $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 0]);
        $this->assertSame(0, $this->repository()->paginateLots()->total());

        try {
            $this->createTransfer();
            $this->fail('Se permitió enviar un lote sin existencia.');
        } catch (DomainException) {
            $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 0]);
            $this->assertDatabaseCount('transferencias', 1);
        }
    }

    public function test_a_failed_lot_rolls_back_all_quantity_changes(): void
    {
        DB::table('lotes')->insert([
            'id' => 2, 'producto' => 1, 'lote' => 'L-002',
            'fecha_elab' => '2026-01-01', 'fecha_exp' => '2028-01-01',
            'cantidad' => 1, 'costo' => 20, 'isv' => false,
        ]);
        try {
            $this->repository()->send(1, 1, [
                'tipo' => 1, 'stock_para' => 2,
                'detalles' => [['lote' => 1, 'cantidad' => 2], ['lote' => 2, 'cantidad' => 2]],
            ]);
            $this->fail('Se permitió enviar una cantidad superior a la existencia.');
        } catch (DomainException) {
            $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 10]);
            $this->assertDatabaseHas('lotes', ['id' => 2, 'cantidad' => 1]);
            $this->assertDatabaseCount('transferencias', 0);
            $this->assertDatabaseCount('trasferencia_detalle', 0);
        }
    }

    public function test_invalid_quantities_empty_transfers_and_duplicate_lots_do_not_change_stock(): void
    {
        foreach ([
            [],
            [['lote' => 1, 'cantidad' => 0]],
            [['lote' => 1, 'cantidad' => -1]],
            [['lote' => 1, 'cantidad' => 1.5]],
            [['lote' => 1, 'cantidad' => 1], ['lote' => 1, 'cantidad' => 1]],
        ] as $details) {
            try {
                $this->repository()->send(1, 1, ['tipo' => 1, 'stock_para' => 2, 'detalles' => $details]);
                $this->fail('Se permitió enviar detalles inválidos.');
            } catch (DomainException) {
                $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 10]);
                $this->assertDatabaseCount('transferencias', 0);
            }
        }
        foreach ([[], ['tipo' => 1, 'stock_para' => 2, 'detalles' => []]] as $payload) {
            $request = TransferSendRequest::create('/api/farma/transferencias/enviar', 'POST', $payload);
            $this->assertTrue(Validator::make($request->all(), $request->rules())->fails());
        }
    }

    public function test_sent_transfers_cannot_change_details_or_deduct_stock_twice(): void
    {
        $transfer = $this->createTransfer();
        try {
            $this->repository()->updateDetails($transfer->id, 1, ['detalles' => [['lote' => 1, 'cantidad' => 4]]]);
            $this->fail('Se permitió modificar una transferencia enviada.');
        } catch (DomainException) {
            $this->assertDatabaseHas('lotes', ['id' => 1, 'cantidad' => 9]);
            $this->assertDatabaseHas('trasferencia_detalle', ['transferencia_id' => $transfer->id, 'cantidad' => 1]);
            $this->assertDatabaseHas('transferencias', ['id' => $transfer->id, 'items' => 1, 'total' => 11.50]);
        }
    }

    private function createTransfer(): Transferencia
    {
        return $this->repository()->send(1, 1, [
            'tipo' => 1,
            'stock_para' => 2,
            'detalles' => [['lote' => 1, 'cantidad' => 1]],
        ]);
    }

    private function repository(): TransferRepository
    {
        return new TransferRepository(new Transferencia);
    }

    private function seedData(): void
    {
        DB::table('stock_estado')->insert([
            ['id' => 1, 'descripcion' => 'ACTIVO'],
            ['id' => 2, 'descripcion' => 'INACTIVO'],
        ]);
        DB::table('stocks')->insert([
            ['id' => 1, 'descripcion' => 'Origen', 'telefono' => '1', 'ubicacion' => 'A', 'estado' => 1],
            ['id' => 2, 'descripcion' => 'Destino', 'telefono' => '2', 'ubicacion' => 'B', 'estado' => 1],
            ['id' => 7, 'descripcion' => 'Inactivo', 'telefono' => '7', 'ubicacion' => 'C', 'estado' => 2],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'nombre' => 'Emisor', 'name' => 'sender', 'email' => 'sender@example.test', 'password' => 'x'],
            ['id' => 2, 'nombre' => 'Receptor', 'name' => 'receiver', 'email' => 'receiver@example.test', 'password' => 'x'],
        ]);
        DB::table('laboratorios')->insert(['id' => 1, 'nombre' => 'Laboratorio de prueba']);
        DB::table('prod_categorias')->insert(['id' => 1, 'nombre' => 'Medicamentos']);
        DB::table('prod_unidades')->insert(['id' => 1, 'abreviatura_c' => 'CJ', 'cantidad_c' => 100, 'abreviatura_v' => 'UND', 'cantidad_v' => 1]);
        DB::table('fam_presentacion')->insert(['id' => 1, 'descripcion' => 'Tableta']);
        DB::table('fam_administracion')->insert(['id' => 1, 'descripcion' => 'Oral']);
        DB::table('familia')->insert(['id' => 1, 'descripcion' => 'Analgésico', 'presentacion' => 1, 'administracion' => 1]);
        DB::table('compras')->insert(['id' => 1, 'nro' => 'FAC-100']);
        DB::table('productos')->insert(['id' => 1, 'codigo' => 'P-1', 'descripcion' => 'Producto', 'codigobar' => '7701234567890', 'laboratorio' => 1, 'categoria' => 1, 'unidad' => 1, 'familia' => 1]);
        DB::table('lotes')->insert([
            'id' => 1,
            'compra' => 1,
            'producto' => 1,
            'lote' => 'L-001',
            'fecha_elab' => '2026-01-01',
            'fecha_exp' => '2028-01-01',
            'cantidad' => 10,
            'costo' => 10,
            'isv' => true,
        ]);
        DB::table('transferencia_estado')->insert([
            ['id' => 1, 'descripcion' => 'ENVIADA'],
            ['id' => 2, 'descripcion' => 'PENDIENTE'],
            ['id' => 3, 'descripcion' => 'RECIBIDA'],
        ]);
        DB::table('transferencias_tipo')->insert(['id' => 1, 'descripcion' => 'TRASLADO ENTRE STOCKS']);
    }

    private function createSchema(): void
    {
        Schema::create('stock_estado', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('stocks', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
            $table->string('telefono');
            $table->string('ubicacion');
            $table->integer('estado');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->string('descripcion');
            $table->string('codigobar')->nullable();
            foreach (['laboratorio', 'categoria', 'unidad', 'familia'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->timestamps();
        });
        foreach (['laboratorios', 'prod_categorias'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('nombre');
            });
        }
        foreach (['fam_presentacion', 'fam_administracion', 'familia'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('descripcion');
                if ($name === 'familia') {
                    $table->integer('presentacion')->nullable();
                    $table->integer('administracion')->nullable();
                }
            });
        }
        Schema::create('prod_unidades', function (Blueprint $table): void {
            $table->id();
            $table->string('abreviatura_c');
            $table->integer('cantidad_c');
            $table->string('abreviatura_v');
            $table->integer('cantidad_v');
        });
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->string('nro');
        });
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->integer('compra')->nullable();
            $table->integer('producto');
            $table->string('lote');
            $table->date('fecha_elab');
            $table->date('fecha_exp');
            $table->integer('cantidad');
            $table->decimal('costo', 10, 2)->nullable();
            $table->boolean('isv')->nullable();
            $table->timestamps();
        });
        Schema::create('transferencia_estado', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('transferencias_tipo', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('transferencias', function (Blueprint $table): void {
            $table->id();
            $table->integer('tipo');
            $table->integer('stock_de');
            $table->integer('stock_para');
            $table->unsignedBigInteger('usuario_envia');
            $table->unsignedBigInteger('usuario_recibe')->nullable();
            $table->integer('items');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('isvtotal', 8, 2);
            $table->decimal('total', 10, 2);
            $table->integer('estado');
            $table->integer('estado_recepcion');
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('recibido_at')->nullable();
            $table->timestamps();
        });
        Schema::create('trasferencia_detalle', function (Blueprint $table): void {
            $table->id();
            $table->integer('transferencia_id');
            $table->integer('lote');
            $table->integer('cantidad');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('isv', 8, 2);
            $table->decimal('total', 10, 2);
            $table->timestamps();
        });
    }
}
