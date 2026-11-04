<?php

namespace Tests\Feature\Farma;

use App\DTO\Farma\PurchaseData;
use App\Http\Controllers\API\Farma\Compras\PurchaseCatalogController;
use App\Http\Controllers\API\Farma\Compras\PurchaseReportController;
use App\Http\Requests\Farma\Compras\PurchaseRequest;
use App\Http\Requests\Farma\Compras\PurchaseTransactionRequest;
use App\Http\Resources\Farma\PurchaseDetailResource;
use App\Http\Resources\Farma\PurchaseResource;
use App\Models\Farma\Compra;
use App\Models\Farma\CompraTransaccion;
use App\Repositories\Farma\PurchaseCatalogRepository;
use App\Repositories\Farma\PurchaseKardexRepository;
use App\Repositories\Farma\PurchaseRepository;
use App\Repositories\Farma\PurchaseTransactionRepository;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PurchaseDomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->createSchema();
        $this->seedOptions();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_purchase_export_filters_use_a_cursor_and_include_every_payment_without_documents(): void
    {
        DB::table('compra_tipo')->insert(['int' => 2, 'descripcion' => 'Crédito']);
        DB::table('proveedores')->insert(['id' => 2, 'nombre' => 'Otro proveedor']);
        foreach (range(1, 103) as $id) {
            DB::table('compras')->insert(['id' => $id, 'tipo' => $id === 103 ? 1 : 2, 'proveedor' => $id === 102 ? 2 : 1,
                'usuario' => 1, 'plazo' => 30, 'nro' => 'EXPORT-'.$id, 'items' => 1, 'subtotal' => 100, 'isv' => 15, 'total' => 115]);
        }
        foreach (range(1, 18) as $id) {
            DB::table('compra_transc')->insert(['nro' => 'AB-'.$id, 'compra' => 1, 'tipo' => 2, 'valor' => 1, 'img' => 'private-document']);
        }
        $controller = new PurchaseReportController(new PurchaseRepository(new Compra));
        $filters = ['tipo' => 2, 'proveedor' => 1, 'search' => 'EXPORT-'];
        $response = $controller->export(Request::create('/', 'GET', $filters))->getData(true)['data'];
        $this->assertCount(100, $response['items']);
        $this->assertSame(101, $response['items'][0]['id']);
        $this->assertSame(2, $response['next_cursor']);
        $response = $controller->export(Request::create('/', 'GET', [...$filters, 'antes_de' => 2]))->getData(true)['data'];
        $this->assertCount(1, $response['items']);
        $this->assertNull($response['next_cursor']);
        $this->assertSame(1, $response['items'][0]['id']);
        $this->assertCount(18, $response['items'][0]['pagos']);
        foreach ($response['items'][0]['pagos'] as $payment) {
            $this->assertSame(1, $payment['compra_id']);
            $this->assertArrayNotHasKey('img', $payment);
        }
        $empty = $controller->export(Request::create('/', 'GET', ['search' => 'not-found']))->getData(true)['data'];
        $this->assertSame([], $empty['items']);
        $this->assertNull($empty['next_cursor']);
        $this->assertSame(422, $controller->export(Request::create('/', 'GET', ['antes_de' => 0]))->getStatusCode());
    }

    public function test_purchase_report_payments_are_scoped_and_keep_global_invoice_totals_across_pages(): void
    {
        foreach ([1, 2] as $id) {
            DB::table('compras')->insert(['id' => $id, 'tipo' => 1, 'proveedor' => 1, 'usuario' => 1, 'plazo' => 0,
                'nro' => 'PAY-'.$id, 'items' => 1, 'subtotal' => 100, 'isv' => 15, 'total' => 115]);
        }
        foreach (range(1, 16) as $id) {
            DB::table('compra_transc')->insert(['nro' => 'PAYMENT-'.$id, 'compra' => 1, 'tipo' => 2, 'valor' => 0.50]);
        }
        DB::table('compra_transc')->insert(['nro' => 'OTHER', 'compra' => 2, 'tipo' => 1, 'valor' => 115]);
        $controller = new PurchaseReportController(new PurchaseRepository(new Compra));
        $transactions = new PurchaseTransactionRepository(new CompraTransaccion);
        foreach ([1 => 15, 2 => 1] as $number => $count) {
            $request = Request::create('/api/farma/reporte-compras/pagos/1', 'GET', ['page' => $number]);
            $this->app->instance('request', $request);
            $response = $controller->payments($request, 1, $transactions)->getData(true);
            $this->assertCount($count, $response['data']['items']);
            $this->assertSame(16, $response['data']['meta']['total']);
            $this->assertSame($number, $response['data']['meta']['current_page']);
            $this->assertEquals(8, $response['data']['purchase']['total_aplicado']);
            $this->assertEquals(107, $response['data']['purchase']['saldo']);
            foreach ($response['data']['items'] as $item) {
                $this->assertSame(1, $item['compra_id']);
                $this->assertArrayNotHasKey('img', $item);
            }
        }
        $this->assertSame(404, $controller->payments(Request::create('/'), 999, $transactions)->getStatusCode());
        $this->assertSame(422, $controller->payments(Request::create('/', 'GET', ['page' => 0]), 1, $transactions)->getStatusCode());
    }

    public function test_purchase_report_totals_cover_all_filtered_pages_without_multiplying_invoices(): void
    {
        DB::table('compra_tipo')->insert(['int' => 2, 'descripcion' => 'Crédito']);
        DB::table('proveedores')->insert(['id' => 2, 'nombre' => 'Otro proveedor']);
        foreach ([1 => [1, 2], 2 => [1, 2], 3 => [2, 2], 4 => [1, 1]] as $id => [$provider, $type]) {
            DB::table('compras')->insert([
                'id' => $id, 'tipo' => $type, 'proveedor' => $provider, 'usuario' => 1,
                'plazo' => 30, 'nro' => 'REP-'.$id, 'items' => 1, 'subtotal' => 100.10,
                'isv' => 15.02, 'descuento' => 5.01, 'total' => 110.11, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([[1, 2, 10.25], [1, 2, 20.50], [1, 3, 5], [2, 2, 7.25], [3, 2, 80], [4, 1, 110.11]] as [$purchase, $type, $value]) {
            DB::table('compra_transc')->insert(['compra' => $purchase, 'tipo' => $type, 'valor' => $value]);
        }
        $repository = new PurchaseRepository(new Compra);
        $report = $repository->report(1, '', 1, 2);
        $this->assertSame(2, $report['purchases']->total());
        $this->assertCount(1, $report['purchases']);
        $this->assertSame([
            'subtotal' => 200.20, 'isv' => 30.04, 'descuento' => 10.02,
            'total' => 220.22, 'saldo_pendiente' => 177.22, 'gran_total' => 220.22, 'abonos' => 38.0,
        ], $report['totals']);
        $row = PurchaseResource::make($report['purchases']->items()[0])->resolve(request());
        $this->assertSame(7.25, $row['total_abonos']);
        $this->assertSame(102.86, $row['saldo']);
        $this->assertArrayNotHasKey('detalles', $row);
        $this->assertArrayNotHasKey('img', $row);
        $this->assertSame(330.33, $repository->report(1, '', null, 2)['totals']['total']);
        $this->assertSame(118.0, $repository->report(1, '', null, 2)['totals']['abonos']);
        $cash = $repository->report(1, '', null, 1);
        $this->assertSame(1, $cash['purchases']->total());
        $this->assertSame(0.0, $cash['totals']['abonos']);
        $this->assertSame(0.0, $cash['totals']['saldo_pendiente']);
        DB::table('compra_transc')->insert(['compra' => 3, 'tipo' => 3, 'valor' => 40]);
        $this->assertSame(177.22, $repository->report(1, '', null, 2)['totals']['saldo_pendiente']);
        $this->assertSame(110.11, $repository->report(1, 'REP-1', 1, 2)['totals']['total']);
        $empty = $repository->report(1, 'inexistente', 1, 2);
        $this->assertSame(0, $empty['purchases']->total());
        $this->assertSame(0.0, $empty['totals']['gran_total']);
        $this->assertSame(0.0, $empty['totals']['abonos']);
        $this->assertSame(0.0, $empty['totals']['saldo_pendiente']);
    }

    public function test_purchase_repository_calculates_totals_and_hides_documents(): void
    {
        $dto = PurchaseData::fromValidated([
            'tipo' => 1, 'proveedor' => 1, 'plazo' => 30, 'nro' => 'F-001',
            'nota' => 'Compra de prueba', 'img' => base64_encode('document'),
            'detalles' => [
                ['producto' => 1, 'cantidad' => 2, 'lote' => 'L-01', 'fecha_elaboracion' => '2026-01-10', 'fecha_expiracion' => '2028-01-10', 'costo' => 50.03, 'isv' => true, 'descuento' => 5],
                ['producto' => 2, 'cantidad' => 1, 'lote' => 'L-02', 'fecha_elaboracion' => '2026-02-10', 'fecha_expiracion' => '2028-02-10', 'costo' => 40, 'isv' => false, 'descuento' => 2],
            ],
        ]);
        $purchase = (new PurchaseRepository(new Compra))->create($dto->header(1), $dto->detailRows());

        $this->assertSame('140.06', $purchase->subtotal);
        $this->assertSame('15.00', $purchase->isv);
        $this->assertSame('12.00', $purchase->descuento);
        $this->assertSame('143.06', $purchase->total);
        $this->assertSame(3, $purchase->items);
        $this->assertSame('105.06', $purchase->detalles->first()->total);
        $this->assertSame('38.00', $purchase->detalles->last()->total);

        $payload = PurchaseResource::make($purchase)->resolve(request());
        $this->assertArrayNotHasKey('en_escala', $payload);
        $detailPayload = PurchaseDetailResource::make($purchase->detalles->first())->resolve(request());
        $this->assertSame(143.06, $payload['saldo']);
        $this->assertSame(12.0, $payload['descuento']);
        $this->assertSame('Usuario', $payload['usuario']['nombre']);
        $this->assertSame(7.5, $detailPayload['isv_unitario']);
        $this->assertSame(15.0, $detailPayload['isv_valor']);
        $this->assertSame(10.0, $detailPayload['descuento_valor']);
        $this->assertSame('2026-01-10', $detailPayload['fecha_elaboracion']);
        $this->assertSame('2028-01-10', $detailPayload['fecha_expiracion']);
        $this->assertSame('Producto gravado', $detailPayload['producto']['descripcion']);
        $this->assertSame('Laboratorio prueba', $detailPayload['producto']['laboratorio']);
        $this->assertSame('Tableta', $detailPayload['producto']['presentacion']);
        $this->assertSame(1, $detailPayload['producto']['unidad_compra']);
        $this->assertArrayNotHasKey('img', $payload);

        $productOption = (new PurchaseRepository(new Compra))->options()['productos'][0];
        $this->assertSame('Producto exento', $productOption['label']);
        $this->assertStringNotContainsString('P-2', $productOption['label']);
        $this->assertSame('Laboratorio prueba', $productOption['laboratorio']);
        $this->assertSame('Tableta', $productOption['presentacion']);
        $this->assertSame(1, $productOption['unidad_compra']);
        $providerOption = (new PurchaseRepository(new Compra))->options()['proveedores'][0];
        $this->assertTrue($providerOption['tiene_imagen']);
        $this->assertSame(base64_encode('provider-image'), (new PurchaseRepository(new Compra))->providerImage(1));

        $repository = new PurchaseRepository(new Compra);
        $compactOptions = $repository->options(true);
        $this->assertArrayHasKey('tipos', $compactOptions);
        $this->assertArrayNotHasKey('proveedores', $compactOptions);
        $this->assertArrayNotHasKey('productos', $compactOptions);

        DB::table('proveedores')->insert(['id' => 2, 'nombre' => 'Otro proveedor', 'imagen' => null]);
        $this->assertSame(2, $repository->providers(1)->total());
        $this->assertSame(1, $repository->providers(1)->count());
        $this->assertSame('Otro proveedor', $repository->providers(10, 'Otro')->items()[0]->nombre);
        $this->assertSame(0, $repository->providers(10, 'Inexistente')->total());

        $this->assertSame($purchase->id, $repository->paginate(30, 'Proveedor')->items()[0]->id);
        $this->assertSame($purchase->id, $repository->paginate(30, 'Producto gravado')->items()[0]->id);
        $this->assertSame($purchase->id, $repository->paginate(30, '143.06')->items()[0]->id);
        $this->assertSame(0, $repository->paginate(30, 'sin coincidencias')->total());
    }

    public function test_draft_purchase_synchronizes_products_and_can_be_finalized(): void
    {
        $repository = new PurchaseRepository(new Compra);
        $purchase = $repository->createDraft([
            'tipo' => 2,
            'proveedor' => 1,
            'usuario' => 1,
            'plazo' => 30,
            'nro' => 'BOR-001',
            'nota' => null,
        ]);

        $this->assertSame('borrador', $purchase->estado);
        $this->assertCount(0, $purchase->detalles);

        $purchase = $repository->syncDetail($purchase->id, [
            'producto' => 1,
            'cantidad' => 2,
            'lote' => 'LOTE-1',
            'fecha_elaboracion' => '2026-01-10',
            'fecha_expiracion' => '2028-01-10',
            'costo' => 50.03,
            'isv' => true,
            'descuento' => 5,
        ]);

        $this->assertNotNull($purchase);
        $this->assertSame('100.06', $purchase->subtotal);
        $this->assertSame('105.06', $purchase->total);
        $this->assertCount(1, $purchase->detalles);

        $detailId = $purchase->detalles->first()->id;
        $purchase = $repository->syncDetail($purchase->id, [
            'id' => $detailId,
            'producto' => 1,
            'cantidad' => 3,
            'lote' => 'LOTE-1',
            'fecha_elaboracion' => '2026-01-10',
            'fecha_expiracion' => '2028-01-10',
            'costo' => 50.03,
            'isv' => true,
            'descuento' => 5,
        ]);

        $this->assertNotNull($purchase);
        $this->assertSame(3, $purchase->items);
        $this->assertSame('157.59', $purchase->total);
        $this->assertCount(1, $purchase->detalles);
        $this->assertSame('pendiente', $repository->finalize($purchase->id)?->estado);
    }

    public function test_purchase_is_sent_to_lots_only_once_with_the_user_who_sent_it(): void
    {
        $data = PurchaseData::fromValidated([
            'tipo' => 1, 'proveedor' => 1, 'plazo' => 30, 'nro' => 'F-KARDEX',
            'detalles' => [
                ['producto' => 1, 'cantidad' => 2, 'lote' => 'K-01', 'fecha_elaboracion' => '2026-01-01', 'fecha_expiracion' => '2028-01-01', 'costo' => 50, 'isv' => true, 'descuento' => 0],
                ['producto' => 2, 'cantidad' => 3, 'lote' => 'K-02', 'fecha_elaboracion' => '2026-02-01', 'fecha_expiracion' => '2028-02-01', 'costo' => 25, 'isv' => false, 'descuento' => 0],
            ],
        ]);
        $purchases = new PurchaseRepository(new Compra);
        $purchase = $purchases->create($data->header(1), $data->detailRows());
        $kardex = new PurchaseKardexRepository(new Compra, $purchases);

        $sent = $kardex->send($purchase->id, 1);

        $this->assertNotNull($sent);
        $this->assertNotNull($sent->kardex_enviado_at);
        $this->assertSame(1, $sent->kardex_usuario);
        $this->assertDatabaseCount('lotes', 2);
        $this->assertDatabaseHas('lotes', [
            'compra' => $purchase->id,
            'producto' => 1,
            'lote' => 'K-01',
            'fecha_elab' => '2026-01-01',
            'fecha_exp' => '2028-01-01',
            'cantidad' => 2,
            'costo' => 50,
            'isv' => 1,
        ]);

        $payload = PurchaseResource::make($sent)->resolve(request());
        $this->assertTrue($payload['kardex_enviado']);
        $this->assertSame('Usuario', $payload['kardex_usuario']['nombre']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya fue enviada al Kardex');
        $kardex->send($purchase->id, 1);
    }

    public function test_paid_and_bonus_lines_of_the_same_product_keep_separate_lots_and_values(): void
    {
        $line = ['producto' => 1, 'cantidad' => 10, 'lote' => 'MISMO-LOTE', 'fecha_elaboracion' => '2026-01-01',
            'fecha_expiracion' => '2028-01-01', 'costo' => 50, 'isv' => false, 'descuento' => 0, 'es_bonificacion' => false];
        $payload = ['tipo' => 1, 'proveedor' => 1, 'plazo' => 30, 'nro' => 'BONUS-001',
            'detalles' => [$line, [...$line, 'cantidad' => 2, 'costo' => 0, 'es_bonificacion' => true]]];
        $request = PurchaseRequest::create('/api/farma/compras/crear', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);
        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
        $dto = PurchaseData::fromValidated($validator->validated());
        $repository = new PurchaseRepository(new Compra);
        $purchase = $repository->create($dto->header(1), $dto->detailRows());
        $this->assertSame('500.00', $purchase->total);
        $this->assertSame([false, true], $purchase->detalles->pluck('es_bonificacion')->all());
        $detail = PurchaseDetailResource::make($purchase->detalles->last())->resolve(request());
        $this->assertTrue($detail['es_bonificacion']);
        $this->assertSame(0.0, $detail['total']);
        (new PurchaseKardexRepository(new Compra, $repository))->send($purchase->id, 1);
        $this->assertDatabaseHas('lotes', ['compra' => $purchase->id, 'producto' => 1, 'lote' => 'MISMO-LOTE', 'cantidad' => 10, 'es_bonificacion' => false]);
        $this->assertDatabaseHas('lotes', ['compra' => $purchase->id, 'producto' => 1, 'lote' => 'MISMO-LOTE', 'cantidad' => 2, 'es_bonificacion' => true, 'costo' => 0]);

        $payload['nro'] = 'BONUS-002';
        $payload['detalles'][1]['es_bonificacion'] = false;
        $request = PurchaseRequest::create('/api/farma/compras/crear', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('detalles.1.costo'));
    }

    public function test_draft_keeps_bonus_status_when_lines_are_added_updated_and_finalized(): void
    {
        $repository = new PurchaseRepository(new Compra);
        $purchase = $repository->createDraft(['tipo' => 1, 'proveedor' => 1, 'usuario' => 1, 'plazo' => 0, 'nro' => 'BONUS-DRAFT']);
        $line = ['producto' => 1, 'cantidad' => 2, 'lote' => 'BONUS', 'fecha_elaboracion' => '2026-01-01',
            'fecha_expiracion' => '2028-01-01', 'costo' => 0, 'isv' => false, 'descuento' => 0, 'es_bonificacion' => true];
        $purchase = $repository->syncDetail($purchase->id, $line);
        $this->assertTrue($purchase->detalles->first()->es_bonificacion);
        $purchase = $repository->syncDetail($purchase->id, [...$line, 'id' => $purchase->detalles->first()->id, 'cantidad' => 3]);
        $this->assertSame(3, $purchase->detalles->first()->cantidad);
        $purchase = $repository->finalize($purchase->id);
        $this->assertTrue($purchase->detalles->first()->es_bonificacion);
        $this->assertSame('0.00', $purchase->total);
    }

    public function test_purchase_request_rejects_a_discount_above_the_line_total(): void
    {
        $request = PurchaseRequest::create('/api/farma/compras/crear', 'POST', [
            'tipo' => 1,
            'proveedor' => 1,
            'plazo' => 0,
            'nro' => 'F-003',
            'detalles' => [[
                'producto' => 1,
                'cantidad' => 2,
                'lote' => 'L-03',
                'fecha_elaboracion' => '2026-01-10',
                'fecha_expiracion' => '2028-01-10',
                'costo' => 50,
                'isv' => true,
                'descuento' => 58,
            ]],
        ]);
        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('detalles.0.descuento', $validator->errors()->toArray());
    }

    public function test_purchase_request_rejects_expiration_before_manufacturing(): void
    {
        $request = PurchaseRequest::create('/api/farma/compras/crear', 'POST', [
            'tipo' => 1,
            'proveedor' => 1,
            'plazo' => 0,
            'nro' => 'F-004',
            'detalles' => [[
                'producto' => 1,
                'cantidad' => 1,
                'lote' => 'L-04',
                'fecha_elaboracion' => '2028-01-10',
                'fecha_expiracion' => '2026-01-10',
                'costo' => 50,
                'isv' => false,
                'descuento' => 0,
            ]],
        ]);
        $validator = Validator::make($request->all(), $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('detalles.0.fecha_expiracion', $validator->errors()->toArray());
    }

    public function test_pending_purchase_is_presented_as_overdue_after_its_term(): void
    {
        $createdAt = now()->subDays(11);
        $purchase = Compra::query()->create([
            'tipo' => 2, 'proveedor' => 1, 'usuario' => 1, 'plazo' => 10, 'nro' => 'F-VENCIDA',
            'items' => 1, 'isv' => 0, 'subtotal' => 100, 'descuento' => 0, 'total' => 100, 'estado' => 'pendiente',
        ]);
        $purchase->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        $payload = PurchaseResource::make((new PurchaseRepository(new Compra))->find($purchase->id))->resolve(request());

        $this->assertSame('vencida', $payload['estado']);
        $this->assertSame('Vencida', $payload['estado_label']);
        $this->assertSame($createdAt->copy()->addDays(10)->toDateString(), $payload['vence_el']);
    }

    public function test_installments_and_credit_notes_require_a_document_but_cash_payments_do_not(): void
    {
        $purchase = Compra::query()->create([
            'tipo' => 2, 'proveedor' => 1, 'usuario' => 1, 'plazo' => 30, 'nro' => 'F-DOC',
            'items' => 1, 'isv' => 0, 'subtotal' => 100, 'descuento' => 0, 'total' => 100, 'estado' => 'pendiente',
        ]);

        $cash = PurchaseTransactionRequest::create('/api/farma/compras/transacciones/crear', 'POST', [
            'compra' => $purchase->id, 'tipo' => 1, 'valor' => 100,
        ]);
        $cashValidator = Validator::make($cash->all(), $cash->rules(), $cash->messages());
        $cash->withValidator($cashValidator);
        $this->assertFalse($cashValidator->fails());

        $installment = PurchaseTransactionRequest::create('/api/farma/compras/transacciones/crear', 'POST', [
            'compra' => $purchase->id, 'tipo' => 2, 'valor' => 25,
        ]);
        $installmentValidator = Validator::make($installment->all(), $installment->rules(), $installment->messages());
        $installment->withValidator($installmentValidator);
        $this->assertTrue($installmentValidator->fails());
        $this->assertArrayHasKey('img', $installmentValidator->errors()->toArray());

        $creditNote = PurchaseTransactionRequest::create('/api/farma/compras/transacciones/crear', 'POST', [
            'compra' => $purchase->id, 'tipo' => 3, 'valor' => 25, 'img' => base64_encode('document'),
        ]);
        $creditNoteValidator = Validator::make($creditNote->all(), $creditNote->rules(), $creditNote->messages());
        $creditNote->withValidator($creditNoteValidator);
        $this->assertFalse($creditNoteValidator->fails());
    }

    public function test_transactions_reduce_balance_and_cannot_exceed_it(): void
    {
        $purchase = Compra::query()->create([
            'tipo' => 1, 'proveedor' => 1, 'usuario' => 1, 'plazo' => 30, 'nro' => 'F-002',
            'items' => 1, 'isv' => 0, 'subtotal' => 100, 'total' => 100,
        ]);
        $repository = new PurchaseTransactionRepository(new CompraTransaccion);
        $transaction = $repository->create(['compra' => $purchase->id, 'tipo' => 2, 'nro' => 'A-1', 'valor' => 40]);

        $this->assertSame('40.00', $transaction->valor);
        $this->assertSame(60.0, $repository->options()['compras'][0]['saldo']);
        $this->assertSame('pendiente', $purchase->fresh()->estado);

        $repository->create(['compra' => $purchase->id, 'tipo' => 2, 'nro' => 'A-2', 'valor' => 60]);
        $paid = $purchase->fresh();
        $this->assertSame('pagada', $paid->estado);
        $this->assertSame('pagada', PurchaseResource::make((new PurchaseRepository(new Compra))->find($paid->id))->resolve(request())['estado']);

        $this->expectException(DomainException::class);
        $repository->create(['compra' => $purchase->id, 'tipo' => 2, 'nro' => 'A-3', 'valor' => 1]);
    }

    public function test_cash_payment_requires_the_exact_invoice_total_for_cash_or_credit_purchases(): void
    {
        $repository = new PurchaseTransactionRepository(new CompraTransaccion);

        foreach ([1, 2] as $purchaseType) {
            $purchase = Compra::query()->create([
                'tipo' => $purchaseType, 'proveedor' => 1, 'usuario' => 1, 'plazo' => 30,
                'nro' => "F-CASH-{$purchaseType}", 'items' => 1, 'isv' => 0,
                'subtotal' => 100, 'descuento' => 0, 'total' => 100, 'estado' => 'pendiente',
            ]);

            foreach ([99.99, 100.01] as $invalidValue) {
                try {
                    $repository->create(['compra' => $purchase->id, 'tipo' => 1, 'valor' => $invalidValue]);
                    $this->fail('El pago al contado aceptó un valor distinto del total.');
                } catch (DomainException $exception) {
                    $this->assertStringContainsString('exactamente por el total', $exception->getMessage());
                }
            }

            $transaction = $repository->create(['compra' => $purchase->id, 'tipo' => 1, 'valor' => 100]);
            $this->assertSame('100.00', $transaction->valor);
            $this->assertSame('pagada', $purchase->fresh()->estado);
        }
    }

    public function test_cash_payment_is_rejected_after_an_installment_was_applied(): void
    {
        $purchase = Compra::query()->create([
            'tipo' => 2, 'proveedor' => 1, 'usuario' => 1, 'plazo' => 30, 'nro' => 'F-PARTIAL',
            'items' => 1, 'isv' => 0, 'subtotal' => 100, 'descuento' => 0, 'total' => 100, 'estado' => 'pendiente',
        ]);
        $repository = new PurchaseTransactionRepository(new CompraTransaccion);
        $repository->create(['compra' => $purchase->id, 'tipo' => 2, 'valor' => 20]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no tiene abonos o notas aplicadas');
        $repository->create(['compra' => $purchase->id, 'tipo' => 1, 'valor' => 100]);
    }

    public function test_purchase_type_catalog_exposes_legacy_int_key_as_id(): void
    {
        $controller = new PurchaseCatalogController(new PurchaseCatalogRepository);
        $response = $controller->index(Request::create('/api/farma/compras/catalogos/tipos-compra/listar'), 'tipos-compra');
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['data'][0]['id']);
        $this->assertArrayNotHasKey('int', $payload['data'][0]);
    }

    private function seedOptions(): void
    {
        DB::table('users')->insert(['id' => 1, 'nombre' => 'Usuario', 'name' => 'user', 'email' => 'u@example.test', 'password' => 'x']);
        DB::table('proveedores')->insert(['id' => 1, 'nombre' => 'Proveedor', 'imagen' => base64_encode('provider-image')]);
        DB::table('laboratorios')->insert(['id' => 1, 'nombre' => 'Laboratorio prueba']);
        DB::table('prod_unidades')->insert([
            'id' => 1, 'abreviatura_c' => 'CAJ', 'cantidad_c' => 1, 'abreviatura_v' => 'UND', 'cantidad_v' => 20,
        ]);
        DB::table('fam_presentacion')->insert(['id' => 1, 'descripcion' => 'Tableta']);
        DB::table('familia')->insert([
            'id' => 1, 'presentacion' => 1, 'administracion' => 1, 'descripcion' => 'Medicamento',
        ]);
        DB::table('productos')->insert([
            ['id' => 1, 'laboratorio' => 1, 'unidad' => 1, 'familia' => 1, 'codigo' => 'P-1', 'descripcion' => 'Producto gravado'],
            ['id' => 2, 'laboratorio' => 1, 'unidad' => 1, 'familia' => 1, 'codigo' => 'P-2', 'descripcion' => 'Producto exento'],
        ]);
        DB::table('compra_tipo')->insert(['int' => 1, 'descripcion' => 'Contado']);
        DB::table('transc_tipo')->insert([
            ['id' => 1, 'descripcion' => 'Pago al contado'],
            ['id' => 2, 'descripcion' => 'Abono'],
            ['id' => 3, 'descripcion' => 'Nota de crédito'],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->binary('imagen')->nullable();
            $table->timestamps();
        });
        Schema::create('laboratorios', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->timestamps();
        });
        Schema::create('prod_unidades', function (Blueprint $table): void {
            $table->id();
            $table->string('abreviatura_c');
            $table->integer('cantidad_c');
            $table->string('abreviatura_v');
            $table->integer('cantidad_v');
            $table->timestamps();
        });
        Schema::create('fam_presentacion', function (Blueprint $table): void {
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
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratorio');
            $table->unsignedBigInteger('unidad');
            $table->unsignedBigInteger('familia');
            $table->string('codigo');
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('compra_tipo', function (Blueprint $table): void {
            $table->increments('int');
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('transc_tipo', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->integer('tipo');
            $table->integer('proveedor');
            $table->unsignedBigInteger('usuario');
            $table->integer('plazo');
            $table->boolean('en_escala')->nullable();
            $table->string('nro');
            $table->integer('items');
            $table->decimal('isv', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('estado', 20)->default('pendiente');
            $table->timestamp('kardex_enviado_at')->nullable();
            $table->unsignedBigInteger('kardex_usuario')->nullable();
            $table->text('nota')->nullable();
            $table->binary('img')->nullable();
            $table->timestamps();
        });
        Schema::create('compra_detalle', function (Blueprint $table): void {
            $table->id();
            $table->boolean('es_bonificacion')->default(false);
            $table->integer('compra');
            $table->integer('producto');
            $table->integer('cantidad');
            $table->string('lote');
            $table->date('fecha_elaboracion')->nullable();
            $table->date('fecha_expiracion')->nullable();
            $table->decimal('costo', 10, 2);
            $table->boolean('isv');
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->timestamps();
        });
        Schema::create('compra_transc', function (Blueprint $table): void {
            $table->id();
            $table->string('nro')->nullable();
            $table->integer('compra');
            $table->integer('tipo');
            $table->decimal('valor', 10, 2);
            $table->binary('img')->nullable();
            $table->timestamps();
        });
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->boolean('es_bonificacion')->default(false);
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
    }
}
