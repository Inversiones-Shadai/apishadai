<?php

namespace Tests\Feature\Pos;

use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Models\Pos\CatalogoPos;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosDiscountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
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
            $table->string('nombre');
            $table->unsignedBigInteger('estado');
            $table->timestamps();
        });
        Schema::create('vistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->string('nombre');
            $table->unsignedBigInteger('modulo');
            $table->unsignedBigInteger('estado');
            $table->timestamps();
        });
        Schema::create('actionsvistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->string('nombre');
            $table->unsignedBigInteger('vista');
            $table->timestamps();
        });
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('usuario');
            $table->unsignedBigInteger('modulo');
            $table->unsignedBigInteger('vista');
            $table->unsignedBigInteger('actionvista');
            $table->timestamp('lifetime')->nullable();
        });
        (require database_path('migrations/2026_10_01_000022_create_clientepos_table.php'))->up();
        (require database_path('migrations/2026_10_01_000023_register_clientepos_permissions.php'))->up();
        $this->withoutMiddleware([DeviceSecurityMiddleware::class, IdempotencyMiddleware::class, JWTAuthenticationMiddleware::class, MatchTokenMiddleware::class]);
        $this->actingAs((new User)->forceFill(['id' => 7]));

        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);
        Schema::create('stocks', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('descripcion');
            $table->string('telefono')->nullable();
            $table->string('ubicacion')->nullable();
            $table->integer('estado')->nullable();
            $table->timestamps();
        });
        DB::table('stocks')->insert(['id' => 1, 'descripcion' => 'TEST stock']);
        (require database_path('migrations/2026_10_04_000001_create_pos_central_management.php'))->up();
        (require database_path('migrations/2026_10_04_000002_register_pos_management_views.php'))->up();
        foreach (['2026_10_01_000024_create_sync_changes_table.php', '2026_10_01_000030_create_headquarters_outbox.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('sync_entity_versions', fn (Blueprint $table) => $table->json('stock_ids')->nullable());
        Schema::table('sync_changes', function (Blueprint $table): void {
            $table->json('stock_ids')->nullable();
            $table->json('removed_stock_ids')->nullable();
        });
        (require database_path('migrations/2026_10_05_000001_create_pos_permission_hierarchy.php'))->up();
        Schema::create('tipos_tiempo', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->integer('cantidad');
            $table->string('unidad');
        });
        DB::table('tipos_tiempo')->insert(['id' => 1, 'nombre' => 'TEST permanente', 'cantidad' => 0, 'unidad' => 'permanente']);
        (require database_path('migrations/2026_10_05_000002_pos_permissions_belong_to_users.php'))->up();
        (require database_path('migrations/2026_10_05_000003_global_pos_accounts.php'))->up();
        (require database_path('migrations/2026_10_08_160932_add_invoice_seller_to_pos_users.php'))->up();
        (require database_path('migrations/2026_10_12_000001_pos_discount_rules.php'))->up();
        (require database_path('migrations/2026_10_14_000003_register_pos_discount_delete_permission.php'))->up();
        Schema::create('prod_categorias', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->timestamps();
        });
        DB::table('prod_categorias')->insert(['id' => 10, 'nombre' => 'TEST alimentos']);
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->string('descripcion');
            $table->string('codigobar')->nullable();
            $table->text('imagen')->nullable();
            foreach (['categoria', 'laboratorio', 'unidad', 'familia', 'estado', 'producto_padre_id'] as $field) {
                $table->integer($field)->nullable();
            }
            $table->timestamps();
        });
        Schema::create('prod_activo', function (Blueprint $table): void {
            $table->integer('producto');
            $table->integer('pactivo');
        });
        Schema::create('principal_activos', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('productos')->insert(['id' => 20, 'codigo' => 'TEST-P', 'descripcion' => 'TEST producto', 'categoria' => 10]);
        (require database_path('migrations/2026_10_12_000003_global_discount_rules.php'))->up();
    }

    private function permit(string $code): array
    {
        $action = DB::table('actionsvistas')->where('codigo', $code)->first();
        DB::table('permisos')->insert(['usuario' => 7, 'modulo' => DB::table('modulos')->where('codigo', 'pos')->value('id'), 'vista' => $action->vista, 'actionvista' => $action->id, 'lifetime' => null]);

        return ['Action-Btn' => $code];
    }

    public function test_product_picker_returns_ten_images_and_preserves_name_filter_between_pages(): void
    {
        $source = imagecreatetruecolor(256, 192);
        ob_start();
        imagepng($source);
        $image = base64_encode(ob_get_clean());
        unset($source);
        foreach (range(1, 13) as $i) {
            DB::table('productos')->insert(['id' => 100 + $i, 'codigo' => 'UNRELATED-'.$i,
                'descripcion' => sprintf('TEST leche %02d', $i), 'imagen' => $i === 1 ? $image : null]);
        }
        $headers = $this->headers('dcpbtn02');
        $first = $this->getJson('/api/pos/descuentos/opciones?tipo=PRODUCTO&buscar=leche&page=1', $headers)
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 13)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.nombre', 'TEST leche 01')->json('data');
        $this->assertStringStartsWith('data:image/png;base64,', $first[0]['imagen']);
        $details = getimagesizefromstring(base64_decode(substr($first[0]['imagen'], strlen('data:image/png;base64,'))));
        $this->assertSame(128, $details[0]);
        $this->assertSame(96, $details[1]);
        $this->assertNull($first[1]['imagen']);
        $this->getJson('/api/pos/descuentos/opciones?tipo=PRODUCTO&buscar=leche&page=2', $headers)
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.nombre', 'TEST leche 11');
        $this->getJson('/api/pos/descuentos/opciones?tipo=PRODUCTO&buscar=UNRELATED', $headers)->assertOk()->assertJsonCount(0, 'data');
        DB::table('productos')->where('id', 101)->update(['imagen' => 'data:image/svg+xml;base64,'.base64_encode('<svg></svg>')]);
        $this->getJson('/api/pos/descuentos/opciones?tipo=PRODUCTO&buscar=leche', $headers)->assertOk()->assertJsonPath('data.0.imagen', null);
        $this->assertSame('data:image/svg+xml;base64,'.base64_encode('<svg></svg>'), DB::table('productos')->where('id', 101)->value('imagen'));
        $this->getJson('/api/pos/descuentos/opciones?tipo=PRODUCTO', ['Action-Btn' => 'dcpbtn01'])->assertForbidden();
    }

    public function test_global_rules_reject_stock_selection_and_preserve_other_catalog_scopes(): void
    {
        $this->postJson('/api/pos/descuentos/crear', ['stock_id' => 1] + $this->payload(), $this->headers('dcpbtn02'))
            ->assertUnprocessable()->assertJsonPath('message', 'Las reglas se aplican en todos los stocks. No selecciones un stock.');
        DB::table('stocks')->insert(['id' => 2, 'descripcion' => 'TEST another stock']);
        foreach ([1, 2] as $stock) {
            DB::table('catalogospos')->insert(['stock_id' => $stock, 'tipo' => 'MEDIO_PAGO', 'codigo' => 'TEST SAME', 'datos' => '{}', 'activo' => true]);
        }
        $response = $this->postJson('/api/pos/descuentos/crear', $this->payload(), $this->headers('dcpbtn02'))->assertCreated();
        $response->assertJsonPath('data.stock_id', null);
        $this->assertSame(2, DB::table('catalogospos')->where('tipo', 'MEDIO_PAGO')->whereNotNull('stock_id')->count());
    }

    public function test_deployment_converts_existing_general_rule_once_and_publishes_it_globally(): void
    {
        $data = ['version' => 2, 'alcance' => 'CATEGORIA', 'referencia_id' => '10', 'categoria_id' => '10',
            'producto_id' => null, 'descripcion' => 'TEST category', 'revision' => 2, 'regla' => ['modo' => 'EXCLUIR']];
        DB::table('catalogospos')->insert(['id' => 80, 'stock_id' => 1, 'tipo' => 'DESCUENTO', 'codigo' => 'categoria-10', 'datos' => json_encode($data), 'activo' => true]);
        DB::table('sync_entity_versions')->insert(['entity' => 'catalogospos', 'entity_id' => 80, 'version' => 1, 'stock_ids' => '[1]']);
        (require database_path('migrations/2026_10_12_000003_global_discount_rules.php'))->up();
        $rule = CatalogoPos::findOrFail(80);
        $this->assertNull($rule->stock_id);
        $this->assertSame(3, $rule->datos['revision']);
        $change = DB::table('sync_changes')->where('entity', 'catalogospos')->first();
        $this->assertSame([0], json_decode($change->stock_ids, true));
        $this->assertSame([], json_decode($change->removed_stock_ids, true));
        (require database_path('migrations/2026_10_12_000003_global_discount_rules.php'))->up();
        $this->assertSame(1, DB::table('sync_changes')->where('entity', 'catalogospos')->count());
    }

    public function test_journal_failure_rolls_back_catalog_and_outbox_then_retries_same_id(): void
    {
        DB::unprepared("CREATE TRIGGER TEST_discount_failure BEFORE INSERT ON pos_descuento_solicitudes BEGIN SELECT RAISE(ABORT, 'TEST journal failure'); END");
        $headers = $this->headers('dcpbtn02');
        $this->postJson('/api/pos/descuentos/crear', $this->payload(), $headers)->assertStatus(500);
        $this->assertDatabaseCount('catalogospos', 0);
        $this->assertDatabaseCount('pos_descuento_solicitudes', 0);
        $this->assertSame(0, DB::table('sync_changes')->where('entity', 'catalogospos')->count());
        DB::unprepared('DROP TRIGGER TEST_discount_failure');
        $this->postJson('/api/pos/descuentos/crear', $this->payload(), $headers)->assertCreated();
        $this->assertDatabaseCount('catalogospos', 1);
        $this->assertDatabaseCount('pos_descuento_solicitudes', 1);
    }

    private function payload(string $scope = 'CATEGORIA', int $reference = 10): array
    {
        return ['alcance' => $scope, 'referencia_id' => $reference, 'activo' => true,
            'regla' => ['modo' => 'EXCLUIR']];
    }

    private function headers(string $action, ?string $uuid = null): array
    {
        return $this->permit($action) + ['Idempotency-Key' => $uuid ?? (string) Str::uuid()];
    }

    public function test_catalog_registration_is_repeatable_and_never_grants_permissions(): void
    {
        (require database_path('migrations/2026_10_12_000001_pos_discount_rules.php'))->up();
        $this->assertDatabaseCount('permisos', 0);
        (require database_path('migrations/2026_10_14_000003_register_pos_discount_delete_permission.php'))->up();
        $this->assertSame(6, DB::table('actionsvistas')->where('codigo', 'like', 'dcpbtn%')->count());
        $this->assertDatabaseCount('permisos', 0);
        $this->getJson('/api/pos/descuentos/listar', ['Action-Btn' => 'dcpbtn01'])->assertForbidden();
        $this->postJson('/api/pos/descuentos/crear', $this->payload(), $this->headers('copbtn02'))->assertForbidden();
        $this->getJson('/api/pos/descuentos/opciones?tipo=PRODUCTO&buscar=TEST%20producto', $this->headers('dcpbtn02'))
            ->assertOk()->assertJsonPath('data.0.id', 20);
    }

    public function test_retries_preserve_original_response_and_never_duplicate_rules_or_outbox(): void
    {
        $headers = $this->headers('dcpbtn02');
        $data = $this->payload();
        $created = $this->postJson('/api/pos/descuentos/crear', $data, $headers)->assertCreated()->json('data');
        $changes = DB::table('sync_changes')->count();
        $this->postJson('/api/pos/descuentos/crear', $data, $headers)->assertCreated()->assertJsonPath('data', $created);
        $this->assertDatabaseCount('catalogospos', 1);
        $this->assertDatabaseCount('pos_descuento_solicitudes', 1);
        $this->assertSame($changes, DB::table('sync_changes')->count());
        $data['activo'] = false;
        $this->postJson('/api/pos/descuentos/crear', $data, $headers)->assertConflict();
        $this->postJson('/api/pos/descuentos/crear', $this->payload(), $this->headers('dcpbtn02'))->assertUnprocessable();
        $payload = json_decode(DB::table('sync_changes')->where('entity', 'catalogospos')->orderByDesc('id')->value('payload'), true);
        $this->assertSame('DESCUENTO', $payload['tipo']);
        $this->assertSame(2, $payload['datos']['version']);
        $this->assertSame(['modo' => 'EXCLUIR'], $payload['datos']['regla']);
        $this->assertArrayNotHasKey('reglas', $payload['datos']);
        $this->assertSame('10', $payload['datos']['categoria_id']);
        $this->assertNull($payload['stock_id']);
        $this->assertSame([0], json_decode(DB::table('sync_changes')->where('entity', 'catalogospos')->value('stock_ids'), true));
    }

    public function test_identity_revision_and_cross_catalog_permission_are_enforced(): void
    {
        $id = $this->postJson('/api/pos/descuentos/crear', $this->payload('PRODUCTO', 20), $this->headers('dcpbtn02'))->assertCreated()->json('data.id');
        $data = $this->payload('PRODUCTO', 20) + ['revision_esperada' => 1];
        $data['regla'] = ['modo' => 'LOTE'];
        $updated = $this->putJson('/api/pos/descuentos/actualizar/'.$id, $data, $this->headers('dcpbtn04'))->assertOk()->assertJsonPath('data.datos.revision', 2)->json('data');
        $this->putJson('/api/pos/descuentos/actualizar/'.$id, $data, $this->headers('dcpbtn04'))->assertUnprocessable();
        $data['revision_esperada'] = 2;
        $data['alcance'] = 'CATEGORIA';
        $data['referencia_id'] = 10;
        $this->putJson('/api/pos/descuentos/actualizar/'.$id, $data, $this->headers('dcpbtn04'))->assertUnprocessable();
        $this->getJson('/api/pos/catalogos/buscar/'.$id, $this->headers('copbtn03'))->assertNotFound();
        $this->deleteJson('/api/pos/catalogos/desactivar/'.$id, [], $this->headers('copbtn05'))->assertNotFound();
        $headers = $this->headers('dcpbtn05');
        $this->deleteJson('/api/pos/descuentos/desactivar/'.$id, ['revision_esperada' => 2], $headers)->assertOk()->assertJsonPath('data.activo', false)->assertJsonPath('data.datos.revision', 3);
        $this->deleteJson('/api/pos/descuentos/desactivar/'.$id, ['revision_esperada' => 2], $headers)->assertOk()->assertJsonPath('data.datos.revision', 3);
        $data = $this->payload('PRODUCTO', 20) + ['revision_esperada' => 3];
        $this->putJson('/api/pos/descuentos/actualizar/'.$id, $data, $this->headers('dcpbtn04'))->assertOk()->assertJsonPath('data.activo', true);
        $this->assertDatabaseCount('catalogospos', 1);
    }

    public function test_delete_removes_active_and_inactive_rules_and_publishes_a_global_tombstone(): void
    {
        foreach ([true, false] as $active) {
            $data = $this->payload();
            $data['activo'] = $active;
            $id = $this->postJson('/api/pos/descuentos/crear', $data, $this->headers('dcpbtn02'))->assertCreated()->json('data.id');
            $headers = $this->headers('dcpbtn06');
            $deleted = $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], $headers)
                ->assertOk()->assertJsonPath('message', 'Regla eliminada.')->json('data');
            $this->assertDatabaseMissing('catalogospos', ['id' => $id]);
            $change = DB::table('sync_changes')->where('entity', 'catalogospos')->where('entity_id', $id)->orderByDesc('id')->first();
            $this->assertSame('DELETE', $change->action);
            $this->assertSame([0], json_decode($change->stock_ids, true));
            $changes = DB::table('sync_changes')->count();
            $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], $headers)->assertOk()->assertJsonPath('data', $deleted);
            $this->assertSame($changes, DB::table('sync_changes')->count());
            $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 2], $headers)->assertConflict();
            $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], $this->headers('dcpbtn06'))->assertNotFound();
        }
    }

    public function test_delete_requires_its_own_permission_revision_and_discount_record(): void
    {
        $id = $this->postJson('/api/pos/descuentos/crear', $this->payload(), $this->headers('dcpbtn02'))->assertCreated()->json('data.id');
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], $this->headers('dcpbtn05'))->assertForbidden();
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], ['Action-Btn' => 'dcpbtn06'])->assertForbidden();
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 2], $this->headers('dcpbtn06'))->assertUnprocessable();
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, [], $this->headers('dcpbtn06'))->assertUnprocessable();
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], $this->permit('dcpbtn06'))->assertUnprocessable();
        $other = CatalogoPos::create(['stock_id' => 1, 'tipo' => 'MEDIO_PAGO', 'codigo' => 'TEST CASH', 'datos' => [], 'activo' => true]);
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$other->id, ['revision_esperada' => 1], $this->headers('dcpbtn06'))->assertNotFound();
        $this->assertDatabaseHas('catalogospos', ['id' => $id]);
        $this->assertDatabaseHas('catalogospos', ['id' => $other->id]);
    }

    public function test_delete_journal_failure_rolls_back_rule_and_sync_then_retries(): void
    {
        $id = $this->postJson('/api/pos/descuentos/crear', $this->payload(), $this->headers('dcpbtn02'))->assertCreated()->json('data.id');
        $changes = DB::table('sync_changes')->count();
        $headers = $this->headers('dcpbtn06');
        DB::unprepared("CREATE TRIGGER TEST_discount_delete_failure BEFORE INSERT ON pos_descuento_solicitudes BEGIN SELECT RAISE(ABORT, 'TEST journal failure'); END");
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], $headers)->assertStatus(500);
        $this->assertDatabaseHas('catalogospos', ['id' => $id]);
        $this->assertSame($changes, DB::table('sync_changes')->count());
        $this->assertDatabaseMissing('pos_descuento_solicitudes', ['solicitud_id' => $headers['Idempotency-Key']]);
        DB::unprepared('DROP TRIGGER TEST_discount_delete_failure');
        $this->deleteJson('/api/pos/descuentos/eliminar/'.$id, ['revision_esperada' => 1], $headers)->assertOk();
        $this->assertDatabaseMissing('catalogospos', ['id' => $id]);
    }

    public function test_only_a_general_rule_can_be_created_and_missing_idempotency_is_rejected(): void
    {
        $data = $this->payload();
        $data['regla']['modo'] = 'PORCENTAJE';
        $this->postJson('/api/pos/descuentos/crear', $data, $this->headers('dcpbtn02'))->assertUnprocessable();
        $data = $this->payload();
        $data['regla']['porcentaje'] = 0;
        $this->postJson('/api/pos/descuentos/crear', $data, $this->headers('dcpbtn02'))->assertUnprocessable();
        $data = ['stock_id' => 1] + $this->payload();
        unset($data['regla']);
        $data['reglas'] = ['dto1' => ['modo' => 'EXCLUIR'], 'dto2' => ['modo' => 'LOTE'], 'dto3' => ['modo' => 'HEREDAR']];
        $this->postJson('/api/pos/descuentos/crear', $data, $this->headers('dcpbtn02'))->assertUnprocessable();
        $this->postJson('/api/pos/descuentos/crear', $this->payload(), $this->permit('dcpbtn02'))->assertUnprocessable();
        $this->assertDatabaseCount('catalogospos', 0);
    }

    public function test_committed_legacy_requests_replay_exact_response_without_rewriting_their_payload(): void
    {
        $data = ['stock_id' => 1] + $this->payload();
        unset($data['regla']);
        $data['reglas'] = ['dto1' => ['modo' => 'EXCLUIR'], 'dto2' => ['modo' => 'LOTE'], 'dto3' => ['modo' => 'HEREDAR']];
        $headers = $this->headers('dcpbtn02');
        $response = ['id' => 90, 'datos' => ['version' => 1, 'reglas' => $data['reglas']]];
        DB::table('pos_descuento_solicitudes')->insert(['solicitud_id' => $headers['Idempotency-Key'], 'usuario_id' => 7,
            'huella' => hash('sha256', json_encode([7, 'GUARDAR', null, $data], JSON_THROW_ON_ERROR)),
            'respuesta' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $this->postJson('/api/pos/descuentos/crear', $data, $headers)->assertCreated()->assertJsonPath('data', $response);
        $this->assertDatabaseCount('catalogospos', 0);
    }
}
