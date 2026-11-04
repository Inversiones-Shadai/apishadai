<?php

namespace Tests\Feature;

use App\Http\Controllers\API\Farma\InventoryMatrixController;
use App\Http\Middleware\EnsureInventoryMatrixPermission;
use App\Models\User;
use App\Services\Headquarters\InventoryMatrixService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryMatrixChannelTest extends TestCase
{
    private const CLIENT = '10000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['device_estado', 'stock_estado'] as $name) {
            Schema::create($name, fn (Blueprint $t) => [$t->id(), $t->string('descripcion')]);
            DB::table($name)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        Schema::create('stocks', fn (Blueprint $t) => [$t->id(), $t->integer('estado')]);
        DB::table('stocks')->insert([['id' => 1, 'estado' => 1], ['id' => 2, 'estado' => 1]]);
        Schema::create('devices', fn (Blueprint $t) => [$t->id(), $t->integer('stock'), $t->integer('estado'), $t->string('hq_client_uuid')]);
        DB::table('devices')->insert(['id' => 1, 'stock' => 1, 'estado' => 1, 'hq_client_uuid' => self::CLIENT]);
        foreach (['rolespos', 'modulospos', 'vistaspos', 'botonespos', 'usuariospos'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->id();
                $t->boolean('activo');
                $t->string('codigo')->nullable();
                if ($name === 'usuariospos') {
                    $t->integer('rol_id');
                }
                if ($name === 'vistaspos') {
                    $t->integer('modulo_id');
                }
                if ($name === 'botonespos') {
                    $t->integer('vista_id');
                }
            });
        }
        DB::table('rolespos')->insert(['id' => 1, 'activo' => true]);
        DB::table('usuariospos')->insert(['id' => 7, 'rol_id' => 1, 'activo' => true]);
        DB::table('modulospos')->insert(['id' => 1, 'codigo' => 'inventario', 'activo' => true]);
        DB::table('vistaspos')->insert(['id' => 1, 'modulo_id' => 1, 'codigo' => 'inventario.matriz', 'activo' => true]);
        Schema::create('permisosusuariospos', fn (Blueprint $t) => [$t->id(), $t->integer('usuario'), $t->integer('modulo'), $t->integer('vista'), $t->integer('actionvista'), $t->integer('lifetime')->nullable()]);
        foreach ([1 => 'matriz.ver', 2 => 'matriz.modificar'] as $id => $action) {
            DB::table('botonespos')->insert(['id' => $id, 'vista_id' => 1, 'codigo' => $action, 'activo' => true]);
            DB::table('permisosusuariospos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => 1, 'actionvista' => $id]);
        }
        Schema::create('productos', fn (Blueprint $t) => $t->id());
        DB::table('productos')->insert(['id' => 3]);
        (require database_path('migrations/2026_10_16_000001_inventory_matrix.php'))->up();
        (require database_path('migrations/2026_10_16_164632_inventory_matrix_automatic_reorder.php'))->up();
        (require database_path('migrations/2026_10_16_173719_inventory_matrix_positive_reorder.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function payload(): array
    {
        return ['version' => 1, 'request_id' => (string) Str::uuid(), 'stock_id' => '1', 'producto_id' => '3',
            'usuario_pos_id' => '7', 'revision' => 1, 'minimo' => '2.125', 'maximo' => '20', 'punto_reorden' => '5'];
    }

    private function save(array $payload): array
    {
        return app(InventoryMatrixService::class)->execute(1, self::CLIENT, $payload);
    }

    private function balances(string $total = '2', int $revision = 1): array
    {
        return ['version' => 2, 'tipo' => 'EXISTENCIAS', 'request_id' => (string) Str::uuid(),
            'stock_id' => '1', 'producto_id' => '3', 'revision' => $revision, 'existencia_total' => $total];
    }

    private function publication(string $total = '2', int $revision = 1, int $configuration = 1): array
    {
        return ['version' => 4, 'tipo' => 'REORDEN', 'request_id' => (string) Str::uuid(), 'stock_id' => '1', 'producto_id' => '3',
            'revision' => $revision, 'revision_configuracion' => $configuration, 'usuario_pos_id' => '7',
            'minimo' => '3', 'maximo' => '20', 'existencia_total' => $total];
    }

    private function withdrawal(int $revision): array
    {
        return ['version' => 4, 'tipo' => 'RETIRAR_REORDEN', 'request_id' => (string) Str::uuid(), 'stock_id' => '1', 'producto_id' => '3', 'revision' => $revision];
    }

    public function test_publication_requires_positive_reorder_and_withdrawal_preserves_local_settings_history(): void
    {
        foreach (['3', '4'] as $total) {
            try {
                $this->save($this->publication($total));
                $this->fail('No debe publicar reorden cero');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('stock_matriz_inventario', 0);
        $positive = $this->publication('2.9999');
        $confirmed = $this->save($positive);
        $this->assertTrue($confirmed['accepted']);
        $this->assertDatabaseHas('stock_matriz_existencias', ['reorden_activo' => true, 'existencia_total' => 2.9999]);
        DB::table('permisosusuariospos')->delete();
        $this->assertTrue($this->save($this->publication('1', 2))['accepted']);
        $withdraw = $this->withdrawal(3);
        $response = $this->save($withdraw);
        $this->assertTrue($response['accepted']);
        $this->assertSame($response, $this->save($withdraw));
        $this->assertSame($confirmed, $this->save($positive));
        $this->assertDatabaseHas('stock_matriz_existencias', ['reorden_activo' => false, 'revision' => 3]);
        $this->assertDatabaseHas('stock_matriz_inventario', ['minimo' => 3, 'maximo' => 20]);
        $this->assertSame('REVISION_CONFLICTO', $this->save($this->publication('1', 2))['code']);
        $this->assertTrue($this->save($this->publication('1', 4))['accepted']);
        $changed = $this->publication('1', 5, 2);
        $changed['minimo'] = '4';
        $this->assertSame('SIN_ACCESO', $this->save($changed)['code']);
        DB::table('productos')->delete();
        $this->assertTrue($this->save($this->withdrawal(6))['accepted']);
    }

    public function test_empty_stock_with_zero_minimum_publishes_maximum_and_preserves_replay(): void
    {
        $empty = $this->publication('0');
        $empty['minimo'] = '0';
        $empty['maximo'] = '100.125';
        $confirmed = $this->save($empty);
        $this->assertTrue($confirmed['accepted']);
        $this->assertDatabaseHas('stock_matriz_existencias', ['existencia_total' => 0, 'reorden_activo' => true]);
        $this->assertSame($confirmed, $this->save($empty));
        $zeroMaximum = $empty;
        $zeroMaximum['request_id'] = (string) Str::uuid();
        $zeroMaximum['maximo'] = '0';
        try {
            $this->save($zeroMaximum);
            $this->fail('Máximo cero no necesita reposición');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertDatabaseCount('stock_matriz_solicitudes', 1);
    }

    public function test_automatic_balance_updates_are_idempotent_and_cannot_modify_thresholds(): void
    {
        $configuration = $this->payload();
        $configuration['version'] = 2;
        $configuration['minimo'] = '3';
        unset($configuration['punto_reorden']);
        $this->assertTrue($this->save($configuration)['accepted']);
        DB::table('permisosusuariospos')->delete();
        $balances = $this->balances();
        $confirmed = $this->save($balances);
        $this->assertTrue($confirmed['accepted']);
        $this->assertSame($confirmed, $this->save($balances));
        $this->assertDatabaseHas('stock_matriz_existencias', ['stock_id' => 1, 'existencia_total' => 2, 'revision' => 1]);
        $this->assertDatabaseHas('stock_matriz_inventario', ['minimo' => 3, 'maximo' => 20, 'revision' => 1]);
        $this->assertTrue($this->save($this->balances('4.125', 3))['accepted']);
        $this->assertSame('REVISION_CONFLICTO', $this->save($this->balances('9', 2))['code']);
        $this->assertDatabaseHas('stock_matriz_existencias', ['existencia_total' => 4.125, 'revision' => 3]);
        $this->assertSame($confirmed, $this->save($balances));
        $balances['minimo'] = '100';
        try {
            $this->save($balances);
            $this->fail('Existencias no debe modificar mínimo');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('minimo', $error->errors());
        }
        $configuration['request_id'] = (string) Str::uuid();
        $configuration['revision'] = 2;
        $this->assertSame('SIN_ACCESO', $this->save($configuration)['code']);
    }

    public function test_new_configuration_prohibits_manual_reorder_and_balances_cannot_spoof_stock(): void
    {
        $payload = $this->payload();
        $payload['version'] = 2;
        try {
            $this->save($payload);
            $this->fail('Reorden manual');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('punto_reorden', $error->errors());
        }
        $balances = $this->balances();
        $balances['stock_id'] = '2';
        try {
            $this->save($balances);
            $this->fail('Existencias de otro stock');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('stock_matriz_existencias', 0);
    }

    public function test_continuous_local_revisions_accept_the_latest_complete_settings_without_reverting_them(): void
    {
        $first = $this->payload();
        $first['version'] = 3;
        $first['revision'] = 3;
        unset($first['punto_reorden']);
        $confirmed = $this->save($first);
        $this->assertTrue($confirmed['accepted']);
        $latest = $first;
        $latest['request_id'] = (string) Str::uuid();
        $latest['revision'] = 5;
        $latest['minimo'] = '4';
        $latest['maximo'] = '30';
        $this->assertTrue($this->save($latest)['accepted']);
        $old = $latest;
        $old['request_id'] = (string) Str::uuid();
        $old['revision'] = 4;
        $old['minimo'] = '1';
        $this->assertSame('REVISION_CONFLICTO', $this->save($old)['code']);
        $this->assertSame($confirmed, $this->save($first));
        $this->assertDatabaseHas('stock_matriz_inventario', ['revision' => 5, 'minimo' => 4, 'maximo' => 30]);
    }

    public function test_replay_keeps_original_response_after_revocation_and_cannot_change_payload(): void
    {
        $payload = $this->payload();
        $first = $this->save($payload);
        $this->assertTrue($first['accepted']);
        DB::table('permisosusuariospos')->delete();
        $this->assertSame($first, $this->save($payload));
        $this->assertDatabaseCount('stock_matriz_inventario', 1);
        $this->assertDatabaseCount('stock_matriz_solicitudes', 1);
        $payload['maximo'] = '21';
        try {
            $this->save($payload);
            $this->fail('UUID con otro contenido');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_revision_conflict_never_overwrites_confirmed_values(): void
    {
        $this->save($this->payload());
        $payload = $this->payload();
        $payload['minimo'] = '1';
        $this->assertSame('REVISION_CONFLICTO', $this->save($payload)['code']);
        $this->assertDatabaseHas('stock_matriz_inventario', ['revision' => 1, 'minimo' => 2.125]);
        $payload['request_id'] = (string) Str::uuid();
        $payload['revision'] = 2;
        $this->assertTrue($this->save($payload)['accepted']);
        $this->assertDatabaseHas('stock_matriz_inventario', ['revision' => 2, 'minimo' => 1]);
    }

    public function test_each_stock_keeps_an_independent_matrix_and_device_cannot_spoof_it(): void
    {
        $payload = $this->payload();
        $payload['stock_id'] = '2';
        try {
            $this->save($payload);
            $this->fail('Stock ajeno');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->save($this->payload());
        $this->save($this->balances('1.125'));
        DB::table('devices')->where('id', 1)->update(['stock' => 2]);
        $payload['maximo'] = '50';
        $this->assertTrue($this->save($payload)['accepted']);
        $this->assertDatabaseCount('stock_matriz_inventario', 2);
        $this->assertDatabaseHas('stock_matriz_inventario', ['stock_id' => 1, 'maximo' => 20]);
        $this->assertDatabaseHas('stock_matriz_inventario', ['stock_id' => 2, 'maximo' => 50]);
    }

    public function test_modify_without_view_and_expired_or_inactive_metadata_are_rejected(): void
    {
        DB::table('permisosusuariospos')->where('actionvista', 1)->delete();
        $this->assertSame('SIN_ACCESO', $this->save($this->payload())['code']);
        DB::table('permisosusuariospos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => 1, 'actionvista' => 1, 'lifetime' => now()->timestamp - 1]);
        $this->assertSame('SIN_ACCESO', $this->save($this->payload())['code']);
        DB::table('permisosusuariospos')->update(['lifetime' => null]);
        DB::table('vistaspos')->update(['activo' => false]);
        $this->assertSame('SIN_ACCESO', $this->save($this->payload())['code']);
        $this->assertDatabaseCount('stock_matriz_inventario', 0);
    }

    public function test_invalid_range_precision_and_missing_product_never_create_a_matrix(): void
    {
        foreach (['-1', '1.00001', '1000000000000000'] as $invalid) {
            $payload = $this->payload();
            $payload['minimo'] = $invalid;
            try {
                $this->save($payload);
                $this->fail('Cantidad inválida');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('minimo', $error->errors());
            }
        }
        $payload = $this->payload();
        $payload['punto_reorden'] = '30';
        try {
            $this->save($payload);
            $this->fail('Reorden fuera de rango');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $payload = $this->payload();
        $payload['producto_id'] = '4';
        $this->assertSame('PRODUCTO_AUSENTE', $this->save($payload)['code']);
        $this->assertDatabaseCount('stock_matriz_inventario', 0);
    }

    public function test_unsigned_headquarters_request_is_rejected(): void
    {
        $this->postJson('/api/headquarters/pos/inventario/matriz/guardar', ['payload' => $this->payload()])->assertUnauthorized();
    }

    public function test_signed_headquarters_endpoint_stores_the_matrix_and_rejects_nonce_replay(): void
    {
        Schema::create('hq_pos_request_nonces', fn (Blueprint $t) => [$t->uuid('nonce')->primary(), $t->timestamp('expires_at')]);
        config(['headquarters.secret' => str_repeat('t', 64), 'headquarters.key_id' => 'test-hq', 'headquarters.pos_sender_ips' => ['127.0.0.1']]);
        $path = '/api/headquarters/pos/inventario/matriz/guardar';
        $body = json_encode(['device_id' => 1, 'client_uuid' => self::CLIENT, 'payload' => $this->payload()], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $canonical = "POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        $headers = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'application/json',
            'X-HQ-Key-Id' => 'test-hq', 'X-HQ-Timestamp' => $timestamp, 'X-HQ-Nonce' => $nonce,
            'X-HQ-Signature' => hash_hmac('sha256', $canonical, str_repeat('t', 64))]);
        $this->call('POST', $path, [], [], [], $headers, $body)->assertOk()->assertJsonPath('data.accepted', true);
        $this->call('POST', $path, [], [], [], $headers, $body)->assertUnauthorized();
        $this->assertDatabaseCount('stock_matriz_inventario', 1);
    }

    public function test_portal_requires_view_plus_filter_and_returns_only_the_selected_stock(): void
    {
        foreach (['modulo_estados', 'vista_estados'] as $name) {
            Schema::create($name, fn (Blueprint $t) => [$t->id(), $t->string('descripcion')]);
            DB::table($name)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        Schema::create('modulos', fn (Blueprint $t) => [$t->id(), $t->string('codigo'), $t->integer('estado')]);
        Schema::create('vistas', fn (Blueprint $t) => [$t->id(), $t->string('codigo'), $t->integer('modulo'), $t->integer('estado')]);
        Schema::create('actionsvistas', fn (Blueprint $t) => [$t->id(), $t->string('codigo'), $t->integer('vista')]);
        Schema::create('permisos', fn (Blueprint $t) => [$t->id(), $t->integer('usuario'), $t->integer('modulo'), $t->integer('vista'), $t->integer('actionvista'), $t->timestamp('lifetime')->nullable()]);
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm', 'estado' => 1]);
        DB::table('vistas')->insert(['id' => 1, 'codigo' => 'miv', 'modulo' => 1, 'estado' => 1]);
        DB::table('actionsvistas')->insert([['id' => 1, 'codigo' => 'mivbtn01', 'vista' => 1], ['id' => 2, 'codigo' => 'mivbtn02', 'vista' => 1]]);
        Schema::table('stocks', fn (Blueprint $t) => $t->string('descripcion')->default('TEST stock'));
        Schema::table('productos', fn (Blueprint $t) => [$t->string('descripcion')->default('TEST producto'), $t->string('codigo')->default('TEST')]);
        $this->save($this->publication('1.125'));
        DB::table('devices')->where('id', 1)->update(['stock' => 2]);
        $second = $this->publication('1');
        $second['stock_id'] = '2';
        $second['maximo'] = '50';
        $this->save($second);
        $user = new User;
        $user->id = 7;
        $this->actingAs($user);
        Route::get('/test-inventory-matrix', [InventoryMatrixController::class, 'index'])
            ->middleware(EnsureInventoryMatrixPermission::class.':mivbtn01');
        $this->getJson('/test-inventory-matrix', ['Action-Btn' => 'mivbtn01'])->assertForbidden();
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => 1, 'actionvista' => 1]);
        $this->getJson('/test-inventory-matrix', ['Action-Btn' => 'mivbtn01'])->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.existencia_total', fn ($value) => (float) $value === 1.125)
            ->assertJsonPath('data.0.punto_reorden', fn ($value) => (float) $value === 1.875);
        DB::table('devices')->where('id', 1)->update(['stock' => 1]);
        $empty = $this->publication('0', 2, 2);
        $empty['minimo'] = '50';
        $empty['maximo'] = '100';
        $this->assertTrue($this->save($empty)['accepted']);
        $this->getJson('/test-inventory-matrix', ['Action-Btn' => 'mivbtn01'])->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.punto_reorden', fn ($value) => (float) $value === 100.0);
        $empty['request_id'] = (string) Str::uuid();
        $empty['revision'] = 3;
        $empty['revision_configuracion'] = 3;
        $empty['minimo'] = '0';
        $this->assertTrue($this->save($empty)['accepted']);
        $this->getJson('/test-inventory-matrix', ['Action-Btn' => 'mivbtn01'])->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.punto_reorden', fn ($value) => (float) $value === 100.0);
        $this->save($this->withdrawal(4));
        $this->getJson('/test-inventory-matrix', ['Action-Btn' => 'mivbtn01'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/test-inventory-matrix?stock_id=2', ['Action-Btn' => 'mivbtn01'])->assertForbidden();
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => 1, 'actionvista' => 2]);
        $this->getJson('/test-inventory-matrix?stock_id=2&buscar=TEST', ['Action-Btn' => 'mivbtn01'])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.maximo', 50);
        DB::table('permisos')->where('actionvista', 1)->delete();
        $this->getJson('/test-inventory-matrix?stock_id=2', ['Action-Btn' => 'mivbtn01'])->assertForbidden();
    }
}
