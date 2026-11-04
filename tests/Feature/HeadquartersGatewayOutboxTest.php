<?php

namespace Tests\Feature;

use App\Jobs\PushHeadquartersOutboxJob;
use App\Jobs\SendHeadquartersApprovalJob;
use App\Mail\HeadquartersApprovalMail;
use App\Models\Device;
use App\Models\DeviceEstado;
use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use App\Models\Farma\ProdCategoria;
use App\Models\Farma\Transferencia;
use App\Models\Pos\Clientepos;
use App\Models\SyncChange;
use App\Services\Headquarters\ChangeRecorder;
use App\Services\Headquarters\HeadquartersClient;
use App\Services\Headquarters\OutboxSender;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class HeadquartersGatewayOutboxTest extends TestCase
{
    public function test_inventory_valuation_tax_comes_from_the_lot_without_a_sale_price(): void
    {
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('producto');
            $table->string('lote');
            $table->decimal('costo', 19, 4)->nullable();
            $table->boolean('isv')->nullable();
        });
        DB::table('sync_entity_versions')->insertOrIgnore(['entity' => 'productos', 'entity_id' => 101, 'version' => 1]);
        DB::table('lotes')->insert(['id' => 1, 'producto' => 101, 'lote' => 'TEST-VAL', 'costo' => 4, 'isv' => true]);
        config(['farma.purchase_isv_rate' => 0.125]);
        $publish = function (): array {
            DB::transaction(fn () => app(ChangeRecorder::class)->record(Lote::findOrFail(1), 'UPDATE'));

            return SyncChange::where('entity', 'lotes')->orderByDesc('id')->firstOrFail()->payload;
        };
        $payload = $publish();
        $this->assertNull($payload['venta']);
        $this->assertSame(['tasa_isv' => '12.5000', 'tratamiento' => 'GRAVADO'], $payload['valoracion']);
        DB::table('lotes')->where('id', 1)->update(['isv' => false]);
        $this->assertSame(['tasa_isv' => '0.0000', 'tratamiento' => 'EXENTO'], $publish()['valoracion']);
        DB::table('lotes')->where('id', 1)->update(['isv' => null]);
        $this->assertNull($publish()['valoracion']);
        Schema::create('distribucion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lote');
            $table->decimal('precio', 19, 4)->nullable();
            $table->boolean('isv')->nullable();
        });
        DB::table('distribucion')->insert(['id' => 1, 'lote' => 1, 'precio' => null, 'isv' => true]);
        $this->assertSame(['tasa_isv' => '12.5000', 'tratamiento' => 'GRAVADO'], $publish()['valoracion']);
        DB::table('distribucion')->where('id', 1)->update(['isv' => false]);
        $this->assertSame(['tasa_isv' => '0.0000', 'tratamiento' => 'EXENTO'], $publish()['valoracion']);
        DB::table('distribucion')->insert(['id' => 2, 'lote' => 1, 'precio' => 1, 'isv' => false]);
        $this->assertNull($publish()['valoracion']);
        DB::table('distribucion')->where('id', 2)->delete();
        DB::table('lotes')->where('id', 1)->update(['isv' => true]);
        config(['farma.purchase_isv_rate' => 0]);
        $this->assertNull($publish()['valoracion']['tasa_isv']);
        $this->assertSame(4, (int) DB::table('lotes')->value('costo'));
    }

    public function test_nested_lot_metadata_publishes_cost_and_explicit_sale_data_without_changing_inventory(): void
    {
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('producto');
            $table->string('lote');
            $table->date('fecha_elab')->nullable();
            $table->date('fecha_exp')->nullable();
            $table->decimal('costo', 19, 4)->nullable();
        });
        Schema::create('distribucion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lote');
            $table->decimal('precio');
            $table->boolean('isv');
            foreach (['dto1', 'dto2', 'dto3', 'dto4'] as $field) {
                $table->decimal($field)->default(0);
            }
            $table->timestamps();
        });
        DB::table('sync_entity_versions')->insertOrIgnore(['entity' => 'productos', 'entity_id' => 101, 'version' => 1]);
        DB::table('lotes')->insert(['id' => 1, 'producto' => 101, 'lote' => 'TEST-LOT', 'costo' => 12.34]);
        DB::table('distribucion')->insert(['id' => 1, 'lote' => 1, 'precio' => 20, 'isv' => false]);
        (require base_path('database/migrations/2026_10_31_000001_distribution_promotions.php'))->up();
        (require base_path('database/migrations/2026_10_31_000002_distribution_promotion_stocks.php'))->up();
        DB::table('distribucion')->where('id', 1)->update([
            'promocion_activa' => true, 'promocion_porcentaje' => 15, 'promocion_hasta' => '2026-12-31',
            'mostrar_encabezado' => true, 'encabezado_texto' => 'Oferta del lote',
            'promocion_todos_stocks' => false, 'promocion_stock_ids' => '[7]',
        ]);
        DB::transaction(fn () => app(ChangeRecorder::class)->record(
            Distribucion::findOrFail(1), 'UPDATE'));
        $lot = SyncChange::where('entity', 'distribucion')->firstOrFail()->payload['lote_datos'];
        $this->assertSame('12.34', $lot['costo']);
        $this->assertArrayNotHasKey('cantidad', $lot);
        $this->assertArrayNotHasKey('isv', $lot);
        $sale = SyncChange::where('entity', 'distribucion')->firstOrFail()->payload['lote_datos']['venta'];
        $this->assertSame('20.00', $sale['precio']);
        $this->assertSame('0.0000', $sale['tasa_isv']);
        $this->assertSame('EXENTO', $sale['tratamiento']);
        $this->assertFalse($sale['incluye_isv']);
        $this->assertTrue($sale['promocion_activa']);
        $this->assertSame('15.00', $sale['promocion_porcentaje']);
        $this->assertSame('2026-12-31', $sale['promocion_hasta']);
        $this->assertTrue($sale['mostrar_encabezado']);
        $this->assertSame('Oferta del lote', $sale['encabezado_texto']);
        $this->assertFalse($sale['promocion_todos_stocks']);
        $this->assertSame([7], $sale['promocion_stock_ids']);
        $this->assertSame(1, DB::table('hq_outbox')->count());
        DB::table('lotes')->where('id', 1)->update(['costo' => null]);
        DB::transaction(fn () => app(ChangeRecorder::class)->record(
            Distribucion::findOrFail(1), 'UPDATE'));
        $this->assertNull(SyncChange::where('entity', 'distribucion')->orderByDesc('id')->firstOrFail()->payload['lote_datos']['costo']);
        config(['farma.purchase_isv_rate' => 0.125]);
        DB::table('distribucion')->where('id', 1)->update(['isv' => true]);
        DB::transaction(fn () => app(ChangeRecorder::class)->record(
            Distribucion::findOrFail(1), 'UPDATE'));
        $sale = SyncChange::where('entity', 'distribucion')->orderByDesc('id')->firstOrFail()->payload['lote_datos']['venta'];
        $this->assertSame('12.5000', $sale['tasa_isv']);
        $this->assertSame('20.00', $sale['precio']);
        $this->assertSame('GRAVADO', $sale['tratamiento']);
        Schema::create('catalogospos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_id');
            $table->string('tipo');
            $table->string('codigo');
            $table->json('datos');
            $table->boolean('activo');
            $table->timestamps();
        });
        Schema::create('transferencias', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_de');
            $table->unsignedBigInteger('stock_para');
        });
        foreach ([1, 2] as $stock) {
            DB::table('catalogospos')->insert([
                'id' => $stock, 'stock_id' => $stock, 'tipo' => 'PRECIO', 'codigo' => 'TEST', 'datos' => json_encode(['lote_id' => 1]), 'activo' => true]);
        }
        $this->artisan('hq:refresh-lot-costs', ['stock' => 1])->assertSuccessful();
        $this->assertDatabaseHas('sync_changes', ['entity' => 'catalogospos', 'entity_id' => 1]);
        $this->assertDatabaseMissing('sync_changes', ['entity' => 'catalogospos', 'entity_id' => 2]);
        $this->assertSame(6, DB::table('hq_outbox')->count());
        $this->artisan('hq:refresh-lot-costs', ['stock' => 999])->assertFailed();
        $this->assertSame(6, DB::table('hq_outbox')->count());
        Schema::create('trasferencia_detalle', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transferencia_id');
            $table->unsignedBigInteger('lote');
            $table->integer('cantidad');
            $table->decimal('subtotal');
            $table->decimal('isv');
            $table->decimal('total');
        });
        DB::table('transferencias')->insert(['id' => 1, 'stock_de' => 1, 'stock_para' => 2]);
        DB::table('sync_entity_versions')->insertOrIgnore([
            ['entity' => 'stocks', 'entity_id' => 1, 'version' => 1], ['entity' => 'stocks', 'entity_id' => 2, 'version' => 1],
        ]);
        DB::table('trasferencia_detalle')->insert(['id' => 1, 'transferencia_id' => 1, 'lote' => 1,
            'cantidad' => 2, 'subtotal' => 24.68, 'isv' => 0, 'total' => 24.68]);
        DB::transaction(fn () => app(ChangeRecorder::class)->record(
            Transferencia::findOrFail(1), 'UPDATE'));
        $distribution = SyncChange::where('entity', 'distribucion')->orderByDesc('id')->firstOrFail();
        $transfer = SyncChange::where('entity', 'transferencias')->firstOrFail();
        $this->assertLessThan($transfer->id, $distribution->id);
        $this->assertSame('20.00', $transfer->payload['detalles'][0]['lote_datos']['venta']['precio']);
        $this->assertSame('12.5000', $transfer->payload['detalles'][0]['lote_datos']['venta']['tasa_isv']);
        $this->assertDatabaseHas('trasferencia_detalle', ['id' => 1, 'cantidad' => 2, 'subtotal' => 24.68, 'total' => 24.68]);
        Http::assertNothingSent();
    }

    public function test_lot_only_publish_does_not_claim_or_send_unrelated_events(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertCount(1, $request['changes']);
            $this->assertSame('lotes', $request['changes'][0]['entity']);

            return Http::response(['data' => ['accepted' => [$request['changes'][0]['uuid']]]]);
        });
        ProdCategoria::create(['nombre' => 'Synthetic category']);
        $unrelated = DB::table('hq_outbox')->first();
        $lot = SyncChange::create(['uuid' => (string) Str::uuid(), 'entity' => 'lotes',
            'entity_id' => 1, 'action' => 'CREATE', 'version' => 1, 'schema_version' => 1,
            'payload' => ['id' => 1], 'stock_ids' => [], 'removed_stock_ids' => []]);
        DB::table('hq_outbox')->insert(['sync_change_id' => $lot->id]);
        $this->artisan('hq:push-lots')->assertSuccessful();
        $this->assertDatabaseHas('hq_outbox', ['sync_change_id' => $lot->id, 'last_status' => 200]);
        $this->assertEquals($unrelated, DB::table('hq_outbox')->where('id', $unrelated->id)->first());
        $this->assertSame(1, DB::table('hq_outbox')->whereNull('sent_at')->count());
        Http::assertSentCount(1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'headquarters.url' => 'http://127.0.0.1:8001/api', 'headquarters.secret' => str_repeat('a', 64),
            'headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false,
            'headquarters.approval_email' => 'legacy@example.test']);
        DB::purge('sqlite');
        $database = getenv('SYNC_TEST_MYSQL_DATABASE');
        if ($database) {
            if (! preg_match('/\Aapishadai_sync_test_[a-f0-9]+\z/', $database)) {
                throw new RuntimeException('A disposable MySQL database is required.');
            }
            config(['database.default' => 'sync_test', 'database.connections.sync_test' => array_replace(config('database.connections.mysql'), ['database' => $database, 'url' => null, 'engine' => 'InnoDB'])]);
            DB::purge('sync_test');
            if (Schema::getTables($database) !== []) {
                throw new RuntimeException('Test database must be empty.');
            }
        }
        Schema::create('appconfig', function (Blueprint $table): void {
            $table->id();
            $table->string('mail_cc1')->nullable();
        });
        DB::table('appconfig')->insert(['mail_cc1' => 'administrator@example.test']);
        Schema::create('stocks', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('descripcion');
            $table->timestamps();
        });
        DB::table('stocks')->insert([['id' => 1, 'descripcion' => 'Sucursal'], ['id' => 2, 'descripcion' => 'Otra sucursal']]);
        Schema::create('device_estado', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('devices', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('stock');
            $table->integer('estado');
            $table->string('displayname');
            $table->string('ip')->nullable();
            $table->string('ip2')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('device_estado')->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        DB::table('devices')->insert(['id' => 1, 'stock' => 1, 'estado' => 1, 'displayname' => 'POS-01']);
        foreach (['prod_categorias', 'clientepos'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('nombre');
                if ($name === 'clientepos') {
                    $table->string('telefono')->nullable();
                    $table->string('direccion')->nullable();
                    $table->string('rtn')->nullable();
                }
                $table->timestamps();
            });
        }
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        if (DB::getDriverName() === 'mysql') {
            // The fixture exercises only device bindings, not the pharmacy tables.
            Schema::table('devices', fn (Blueprint $table) => $table->uuid('hq_client_uuid')->nullable()->unique());
            DB::table('devices')->update(['hq_client_uuid' => (string) Str::uuid()]);
            Schema::table('sync_entity_versions', fn (Blueprint $table) => $table->json('stock_ids')->nullable());
            Schema::table('sync_changes', function (Blueprint $table): void {
                $table->json('stock_ids')->nullable();
                $table->json('removed_stock_ids')->nullable();
            });
        } else {
            (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        }
        (require database_path('migrations/2026_10_02_000001_add_hq_pairing_uuid_to_devices.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        DB::purge(config('database.default'));
        parent::tearDown();
    }

    public function test_registration_is_forwarded_with_signature_and_code_is_only_emailed(): void
    {
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $uuid = (string) Str::uuid();
        Http::fake(['127.0.0.1:8001/api/internal/apishadai/changes' => fn ($request) => Http::response(['data' => ['accepted' => [$request['changes'][0]['uuid']]]]),
            '127.0.0.1:8001/api/internal/apishadai/registrations' => Http::response(['data' => [
                'registration_id' => $uuid, 'registration_key' => str_repeat('b', 64), 'approval_code' => '123456', 'database_name' => 'ShHQ_Stock1',
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'name' => 'HQ-01', 'ip' => '127.0.0.1', 'client_id_message' => 'headquarters-v1|HQ-01|127.0.0.1',
            ]], 202)]);
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ-01', 'device_id' => 1])
            ->assertStatus(202)->assertJsonPath('data.database_name', 'ShHQ_Stock1')->assertJsonMissing(['approval_code' => '123456'])->assertHeader('Cache-Control', 'no-store, private');
        Mail::assertNothingSent();
        Queue::assertPushed(SendHeadquartersApprovalJob::class, 1);
        Queue::assertPushed(SendHeadquartersApprovalJob::class, function ($job) use ($uuid): bool {
            $this->assertSame($uuid, $job->mail->registrationId);
            $this->assertSame(3, $job->tries);
            $this->assertSame([30, 90], $job->backoff);
            $job->handle(app(HeadquartersClient::class));

            return $job->recipient === 'administrator@example.test';
        });
        Mail::assertSent(HeadquartersApprovalMail::class, fn ($mail): bool => $mail->code === '123456' && $mail->hasTo('administrator@example.test'));
        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/registrations')) {
                return false;
            }
            $time = $request->header('X-Apishadai-Timestamp')[0];
            $nonce = $request->header('X-Apishadai-Nonce')[0];
            $canonical = "POST\n/api/internal/apishadai/registrations\n$time\n$nonce\n".hash('sha256', $request->body());

            return $request['ip'] === '127.0.0.1' && $request->header('X-Apishadai-Signature')[0] === hash_hmac('sha256', $canonical, str_repeat('a', 64));
        });
        $this->assertFalse(Schema::hasTable('headquarters_devices'));
    }

    public function test_registration_resolves_the_paired_uuid_to_the_original_device_and_stock(): void
    {
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $pairingUuid = (string) Str::uuid();
        $device = Device::findOrFail(1);
        $reservedUuid = $device->hq_client_uuid;
        $device->update(['hq_pairing_uuid' => $pairingUuid]);
        Http::fake([
            '127.0.0.1:8001/api/internal/apishadai/changes' => fn ($request) => Http::response(['data' => ['accepted' => [$request['changes'][0]['uuid']]]]),
            '127.0.0.1:8001/api/internal/apishadai/registrations' => Http::response(['data' => [
                'registration_id' => (string) Str::uuid(), 'registration_key' => str_repeat('b', 64),
                'approval_code' => '123456', 'name' => 'HQ', 'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ]], 202),
        ]);
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ', 'device_id' => strtoupper($pairingUuid)])
            ->assertStatus(202)->assertJsonMissing(['approval_code' => '123456']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/registrations')
            && $request['source_device_id'] === 1 && $request['stock_id'] === 1
            && $request['hq_client_uuid'] === $reservedUuid);
        $this->assertSame($reservedUuid, $device->fresh()->hq_client_uuid);
        Queue::assertPushed(SendHeadquartersApprovalJob::class);
        Mail::assertNothingSent();
    }

    public function test_unknown_pairing_uuid_never_contacts_hq_or_sends_mail(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake();
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ', 'device_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('device_id', 'data.errors');
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_pairing_uuid_crud_preserves_reserved_identity_and_can_be_cleared(): void
    {
        $this->withoutMiddleware();
        $reservedUuid = Device::findOrFail(1)->hq_client_uuid;
        $pairingUuid = (string) Str::uuid();
        $this->putJson('/api/config/device/actualizar/1', [
            'id' => 1, 'estado' => ['id' => 1], 'hq_pairing_uuid' => ' '.strtoupper($pairingUuid).' ',
        ])->assertOk();
        $this->assertDatabaseHas('devices', ['id' => 1, 'hq_pairing_uuid' => $pairingUuid, 'hq_client_uuid' => $reservedUuid]);
        $this->getJson('/api/config/device/buscar/1')->assertOk()
            ->assertJsonPath('data.hq_pairing_uuid', $pairingUuid)->assertJsonPath('data.estado.id', 1);
        $this->putJson('/api/config/device/actualizar/1', ['id' => 1, 'estado' => ['id' => 1]])->assertOk();
        $this->assertSame($pairingUuid, Device::findOrFail(1)->hq_pairing_uuid);
        $this->putJson('/api/config/device/actualizar/1', [
            'id' => 1, 'estado' => ['id' => 1], 'hq_pairing_uuid' => '',
        ])->assertOk();
        $this->assertDatabaseHas('devices', ['id' => 1, 'hq_pairing_uuid' => null, 'hq_client_uuid' => $reservedUuid]);
    }

    public function test_pairing_uuid_must_be_valid_and_unique_on_create_and_update(): void
    {
        $this->withoutMiddleware();
        $pairingUuid = (string) Str::uuid();
        Device::findOrFail(1)->update(['hq_pairing_uuid' => $pairingUuid]);
        $other = Device::create(['stock' => 2, 'estado' => 1, 'displayname' => 'POS-02']);
        $payload = ['ip' => '192.0.2.1', 'ip2' => '192.0.2.2', 'name' => 'POS-03', 'displayname' => 'POS-03',
            'stock' => ['id' => 2], 'estado' => ['id' => 1], 'hq_pairing_uuid' => $pairingUuid];
        $this->postJson('/api/config/device/crear', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('hq_pairing_uuid', 'data.errors');
        $this->putJson('/api/config/device/actualizar/'.$other->id, [
            'id' => $other->id, 'estado' => ['id' => 1], 'hq_pairing_uuid' => $pairingUuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('hq_pairing_uuid', 'data.errors');
        $this->putJson('/api/config/device/actualizar/1', [
            'id' => 1, 'estado' => ['id' => 1], 'hq_pairing_uuid' => $pairingUuid,
        ])->assertOk();
        foreach (['invalid', '00000000-0000-0000-0000-000000000000'] as $invalid) {
            $this->postJson('/api/config/device/crear', [...$payload, 'hq_pairing_uuid' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('hq_pairing_uuid', 'data.errors');
        }
        $newUuid = (string) Str::uuid();
        $this->postJson('/api/config/device/crear', [...$payload, 'hq_pairing_uuid' => $newUuid])->assertCreated();
        $this->assertDatabaseHas('devices', ['displayname' => 'POS-03', 'stock' => 2, 'hq_pairing_uuid' => $newUuid]);
    }

    public function test_registration_requires_an_administrator_email_before_contacting_hq(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        DB::table('appconfig')->update(['mail_cc1' => '']);
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ', 'device_id' => 1])->assertStatus(503);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_confirmation_relays_the_client_ip_and_returns_the_operational_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8001/api/internal/apishadai/registrations/confirm' => Http::response(['data' => ['device' => ['name' => 'HQ'], 'headquarters_key' => str_repeat('c', 64)]], 201)]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson('/api/headquarters/registro/confirmar', [
            'registration_id' => (string) Str::uuid(), 'registration_key' => str_repeat('b', 64), 'client_id' => str_repeat('a', 64), 'code' => '123456',
        ])->assertCreated()->assertJsonPath('data.headquarters_key', str_repeat('c', 64));
        Http::assertSent(fn ($request): bool => $request['ip'] === '192.0.2.10');
    }

    public function test_source_has_no_client_update_endpoints(): void
    {
        $this->getJson('/api/headquarters/device')->assertNotFound();
        $this->getJson('/api/headquarters/sync/cambios')->assertNotFound();
        $this->postJson('/api/headquarters/sync/confirmar', ['batch_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_create_update_delete_and_quiet_writes_keep_monotonic_versions_and_full_payloads(): void
    {
        $client = Clientepos::create(['nombre' => 'Ana', 'telefono' => '123']);
        $client->updateQuietly(['nombre' => 'Ana María']);
        $client->deleteQuietly();
        $changes = SyncChange::orderBy('id')->get();
        $this->assertSame(['CREATE', 'UPDATE', 'DELETE'], $changes->pluck('action')->all());
        $this->assertSame([1, 2, 3], $changes->pluck('version')->all());
        $this->assertSame('123', $changes[1]->payload['telefono']);
        $this->assertSame('Ana María', $changes[1]->payload['nombre']);
        $this->assertNull($changes[2]->payload);
        $this->assertDatabaseCount('hq_outbox', 3);
        $this->assertDatabaseHas('sync_entity_versions', ['entity' => 'clientepos', 'entity_id' => $client->id, 'version' => 3]);
    }

    public function test_rollback_does_not_publish_or_leave_changes_or_versions(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['headquarters.send_immediately' => true]);
        try {
            DB::transaction(function (): void {
                ProdCategoria::create(['nombre' => 'Temporal']);
                throw new RuntimeException('Abort business operation');
            });
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('prod_categorias', 0);
        $this->assertDatabaseCount('sync_changes', 0);
        $this->assertDatabaseCount('sync_entity_versions', 0);
        $this->assertDatabaseCount('hq_outbox', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_immediate_publication_is_queued_only_after_the_business_commit_even_with_a_sync_default(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(['data' => ['accepted' => [$request['changes'][0]['uuid']]]]));
        config(['headquarters.send_immediately' => true, 'queue.default' => 'sync',
            'queue.connections.database.connection' => config('database.default'),
            'queue.connections.database.table' => 'jobs']);

        DB::transaction(function (): void {
            ProdCategoria::create(['nombre' => 'Medicamentos']);
            $this->assertDatabaseCount('jobs', 0);
            Http::assertNothingSent();
        });

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('hq_outbox', ['sent_at' => null, 'attempts' => 0, 'claim_token' => null]);
        Http::assertNothingSent();

        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $this->assertSame(PushHeadquartersOutboxJob::class, $job->payload()['displayName']);
        $job->fire();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, DB::table('hq_outbox')->whereNull('sent_at')->count());
        Http::assertSentCount(1);
    }

    public function test_queue_failure_keeps_the_committed_change_available_for_the_scheduled_sender(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(['data' => ['accepted' => [$request['changes'][0]['uuid']]]]));
        config(['headquarters.send_immediately' => true]);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));

        $category = ProdCategoria::create(['nombre' => 'Medicamentos']);

        $this->assertDatabaseHas('prod_categorias', ['id' => $category->id]);
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertDatabaseHas('hq_outbox', ['sent_at' => null, 'attempts' => 0, 'claim_token' => null]);
        Http::assertNothingSent();
        $this->assertSame(['sent' => 1, 'pending' => 0], app(OutboxSender::class)->send());
    }

    public function test_hq_outage_keeps_business_data_and_retry_reuses_the_same_uuid(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            return ++$attempt === 1 ? Http::response([], 503) : Http::response(['data' => ['accepted' => [$request['changes'][0]['uuid']]]]);
        });
        config(['headquarters.send_immediately' => true]);
        $category = ProdCategoria::create(['nombre' => 'Medicamentos']);
        $uuid = SyncChange::firstOrFail()->uuid;
        $this->assertDatabaseHas('prod_categorias', ['id' => $category->id]);
        $this->assertDatabaseHas('hq_outbox', ['sent_at' => null, 'attempts' => 0]);
        Http::assertNothingSent();
        Queue::assertPushed(PushHeadquartersOutboxJob::class, 1);
        app(PushHeadquartersOutboxJob::class)->handle(app(OutboxSender::class));
        $this->assertDatabaseHas('hq_outbox', ['sent_at' => null, 'attempts' => 1]);
        $this->travel(11)->seconds();
        $result = app(OutboxSender::class)->send();
        $this->assertSame(['sent' => 1, 'pending' => 0], $result);
        $this->assertDatabaseCount('sync_changes', 1);
        Http::assertSent(fn ($request): bool => ($request['changes'][0]['uuid'] ?? null) === $uuid);
    }

    public function test_partial_acknowledgement_does_not_mark_the_batch_as_sent(): void
    {
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8001/api/internal/apishadai/changes' => Http::response(['data' => ['accepted' => []]])]);
        ProdCategoria::create(['nombre' => 'Medicamentos']);
        $result = app(OutboxSender::class)->send();
        $this->assertSame(['sent' => 0, 'pending' => 1], $result);
        $this->assertDatabaseHas('hq_outbox', ['attempts' => 1, 'sent_at' => null, 'claim_token' => null]);
    }

    public function test_unexpired_claims_are_not_sent_by_another_worker(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        ProdCategoria::create(['nombre' => 'Medicamentos']);
        DB::table('hq_outbox')->update(['locked_until' => now()->addMinute(), 'claim_token' => (string) Str::uuid()]);
        $this->assertSame(['sent' => 0, 'pending' => 1], app(OutboxSender::class)->send());
        Http::assertNothingSent();
    }

    public function test_a_failed_outbox_insert_rolls_back_the_business_write_and_version(): void
    {
        Schema::drop('hq_outbox');
        try {
            ProdCategoria::create(['nombre' => 'Debe revertirse']);
            $this->fail('The missing outbox must reject the write.');
        } catch (QueryException) {
            $this->assertDatabaseCount('prod_categorias', 0);
            $this->assertDatabaseCount('sync_changes', 0);
            $this->assertDatabaseCount('sync_entity_versions', 0);
        }
    }

    public function test_queue_failure_revokes_the_request_without_returning_secrets(): void
    {
        Http::preventStrayRequests();
        $uuid = (string) Str::uuid();
        Http::fake([
            '127.0.0.1:8001/api/internal/apishadai/changes' => fn ($request) => Http::response(['data' => ['accepted' => [$request['changes'][0]['uuid']]]]),
            '127.0.0.1:8001/api/internal/apishadai/registrations' => Http::response(['data' => [
                'registration_id' => $uuid, 'approval_code' => '123456', 'registration_key' => str_repeat('b', 64),
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ]], 202),
            '127.0.0.1:8001/api/internal/apishadai/registrations/'.$uuid => Http::response(['data' => null]),
        ]);
        Mail::fake();
        $this->mock(Dispatcher::class, function ($dispatcher): void {
            $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable: private connection string'));
        });
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ', 'device_id' => 1])->assertStatus(503)->assertJsonPath('data', null);
        Mail::assertNothingSent();
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'http://127.0.0.1:8001/api/internal/apishadai/registrations/'.$uuid);
    }

    public function test_expired_queued_code_is_not_emailed_and_request_is_revoked(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8001/api/internal/apishadai/registrations/*' => Http::response(['data' => null])]);
        $job = $this->approvalJob(now()->subSecond()->timestamp);
        $job->handle(app(HeadquartersClient::class));
        Mail::assertNothingSent();
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), $job->mail->registrationId));
    }

    public function test_worker_reads_current_mail_cc1_instead_of_the_queued_recipient(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake();
        $job = $this->approvalJob(now()->addMinutes(10)->timestamp);
        DB::table('appconfig')->update(['mail_cc1' => ' new-admin@example.test ']);
        $job->handle(app(HeadquartersClient::class));
        Mail::assertSent(HeadquartersApprovalMail::class, fn ($mail): bool => $mail->hasTo('new-admin@example.test')
            && ! $mail->hasTo('administrator@example.test') && ! $mail->hasTo('legacy@example.test'));
        Http::assertNothingSent();
    }

    public function test_worker_never_falls_back_to_an_old_email_when_mail_cc1_is_invalid(): void
    {
        Mail::fake();
        DB::table('appconfig')->update(['mail_cc1' => 'invalid']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configura un correo válido en appconfig.mail_cc1.');
        try {
            $this->approvalJob(now()->addMinutes(10)->timestamp)->handle(app(HeadquartersClient::class));
        } finally {
            Mail::assertNothingSent();
        }
    }

    public function test_smtp_failure_can_retry_and_only_revokes_when_attempts_are_exhausted(): void
    {
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8001/api/internal/apishadai/registrations/*' => Http::response(['data' => null])]);
        $job = $this->approvalJob(now()->addMinutes(10)->timestamp);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->once()->with('administrator@example.test')->andReturnSelf();
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('535 secret SMTP response'));
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        try {
            $job->handle(app(HeadquartersClient::class));
            $this->fail('SMTP failures must be retried by the worker.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('535', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            Http::assertNothingSent();
            $job->failed($exception);
        }
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), $job->mail->registrationId));
    }

    public function test_approval_code_and_recipient_are_encrypted_in_the_queue_payload(): void
    {
        $job = $this->approvalJob(now()->addMinutes(10)->timestamp);
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $queue = app('queue')->connection('database');
        $queue->push($job, '', 'default');
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $command = $payload['data']['command'];
        $this->assertStringNotContainsString($job->mail->code, $command);
        $this->assertStringNotContainsString($job->recipient, $command);
        $decoded = unserialize(app('encrypter')->decrypt($command));
        $this->assertInstanceOf(SendHeadquartersApprovalJob::class, $decoded);
        $this->assertSame($job->mail->code, $decoded->mail->code);
    }

    private function approvalJob(int $expiresAt): SendHeadquartersApprovalJob
    {
        return new SendHeadquartersApprovalJob('administrator@example.test', new HeadquartersApprovalMail(
            (string) Str::uuid(), 'HQ', '127.0.0.1', 'Sucursal', '654321', 10, 1, 'POS-01',
        ), $expiresAt);
    }

    public function test_registry_retirement_and_outbox_migrations_can_be_rolled_back(): void
    {
        $retirement = require database_path('migrations/2026_10_01_000031_retire_local_headquarters_registry.php');
        $retirement->down();
        $this->assertTrue(Schema::hasTable('headquarters_devices'));
        $this->assertTrue(Schema::hasTable('sync_consumers'));
        $retirement->up();
        $this->assertFalse(Schema::hasTable('headquarters_devices'));
        $this->assertFalse(Schema::hasTable('sync_consumers'));
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->down();
        $this->assertFalse(Schema::hasTable('hq_outbox'));
        $this->assertFalse(Schema::hasTable('sync_entity_versions'));
        $this->assertTrue(Schema::hasTable('sync_changes'));
    }

    public function test_device_creation_reserves_a_stable_hq_identity_and_publishes_only_safe_binding_data(): void
    {
        $device = Device::create(['stock' => 1, 'estado' => 1, 'displayname' => 'POS-02']);
        $uuid = $device->hq_client_uuid;
        $this->assertTrue(Str::isUuid($uuid));
        $change = SyncChange::where('entity', 'devices')->firstOrFail();
        $expected = ['id' => $device->id, 'hq_client_uuid' => $uuid, 'stock_id' => 1, 'active' => true];
        $actual = $change->payload;
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame('devices', $change->entity);
        $device->updateQuietly(['stock' => 2]);
        $this->assertSame($uuid, $device->fresh()->hq_client_uuid);
        $this->assertSame(2, SyncChange::orderByDesc('id')->firstOrFail()->payload['stock_id']);
        DeviceEstado::findOrFail(1)->update(['descripcion' => 'INACTIVO']);
        $this->assertFalse(SyncChange::where('entity', 'devices')->where('entity_id', $device->id)->orderByDesc('id')->firstOrFail()->payload['active']);
    }

    public function test_registration_rejects_a_client_selected_stock_or_missing_device(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ', 'device_id' => 1, 'stock_id' => 2])->assertUnprocessable();
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ'])->assertUnprocessable();
        $this->postJson('/api/headquarters/registro/solicitar', ['name' => 'HQ', 'device_id' => 999])->assertUnprocessable();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_quiet_creation_reserves_identity_and_existing_identity_cannot_be_replaced(): void
    {
        $device = Device::createQuietly(['stock' => 1, 'estado' => 1, 'displayname' => 'HQ quiet']);
        $this->assertTrue(Str::isUuid($device->hq_client_uuid));
        $device->hq_client_uuid = (string) Str::uuid();
        $this->expectException(\LogicException::class);
        $device->saveQuietly();
    }
}
