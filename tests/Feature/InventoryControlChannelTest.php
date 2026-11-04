<?php

namespace Tests\Feature;

use App\Services\Headquarters\InventoryControlService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryControlChannelTest extends TestCase
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
        DB::table('vistaspos')->insert(['id' => 1, 'modulo_id' => 1, 'codigo' => 'inventario.control', 'activo' => true]);
        Schema::create('permisosusuariospos', fn (Blueprint $t) => [$t->id(), $t->integer('usuario'), $t->integer('modulo'), $t->integer('vista'), $t->integer('actionvista'), $t->integer('lifetime')->nullable()]);
        foreach ([1 => 'control.ver', 2 => 'control.finalizar'] as $id => $action) {
            DB::table('botonespos')->insert(['id' => $id, 'vista_id' => 1, 'codigo' => $action, 'activo' => true]);
            DB::table('permisosusuariospos')->insert(['usuario' => 7, 'modulo' => 1, 'vista' => 1, 'actionvista' => $id]);
        }
        Schema::create('productos', fn (Blueprint $t) => $t->id());
        DB::table('productos')->insert(['id' => 3]);
        (require database_path('migrations/2026_10_17_180829_create_stock_inventory_controls.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function payload(): array
    {
        return ['version' => 1, 'request_id' => (string) Str::uuid(), 'stock_id' => '1', 'usuario_pos_id' => '7',
            'inventario_uuid' => (string) Str::uuid(), 'nombre' => 'TEST Conteo',
            'creado_at' => '2026-10-17T08:00:00-06:00', 'finalizado_at' => '2026-10-17T09:00:00-06:00',
            'letras' => [['letra' => 'A', 'productos' => [['producto_id' => '10000000000000000001', 'lote_id' => '10000000000000000011',
                'codigo' => 'TEST-1', 'nombre' => 'TEST Producto', 'lote' => 'TEST Lote',
                'cantidad_sistema' => '5.1250', 'cantidad_conteo' => '2.1250', 'diferencia' => '-3.0000']]]]];
    }

    private function save(array $payload): array
    {
        return app(InventoryControlService::class)->execute(1, self::CLIENT, $payload);
    }

    public function test_version_two_preserves_cost_snapshots_and_unknown_costs(): void
    {
        foreach (['2.1250', '0.0000', null] as $cost) {
            $payload = $this->payload();
            $payload['version'] = 2;
            $payload['letras'][0]['productos'][0]['costo_unitario'] = $cost;
            $response = $this->save($payload);
            $this->assertTrue($response['accepted']);
            $this->assertSame($response, $this->save($payload));
            $saved = json_decode(DB::table('stock_control_inventario')->where('inventario_uuid', $payload['inventario_uuid'])->value('datos'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($cost, $saved['letras'][0]['productos'][0]['costo_unitario']);
        }
    }

    public function test_version_two_requires_cost_field_and_rejects_negative_cost(): void
    {
        foreach (['missing', 'negative'] as $case) {
            $payload = $this->payload();
            $payload['version'] = 2;
            if ($case === 'negative') {
                $payload['letras'][0]['productos'][0]['costo_unitario'] = '-1';
            }
            try {
                $this->save($payload);
                $this->fail('Expected invalid cost');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('letras.0.productos.0.costo_unitario', $error->errors());
            }
        }
        $this->assertDatabaseCount('stock_control_inventario', 0);
    }

    public function test_finalized_document_keeps_snapshots_decimal_ids_and_exactly_once_replay(): void
    {
        $payload = $this->payload();
        $response = $this->save($payload);
        $this->assertTrue($response['accepted']);
        $this->assertSame($response, $this->save($payload));
        $this->assertDatabaseCount('stock_control_inventario', 1);
        $this->assertDatabaseCount('stock_control_inventario_solicitudes', 1);
        $saved = json_decode(DB::table('stock_control_inventario')->value('datos'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($payload, $saved);
        DB::table('permisosusuariospos')->delete();
        $this->assertSame($response, $this->save($payload));
    }

    public function test_same_request_with_changed_content_is_rejected_and_original_kept(): void
    {
        $payload = $this->payload();
        $this->save($payload);
        $payload['nombre'] = 'TEST Cambio';
        $this->expectException(HttpException::class);
        $this->save($payload);
    }

    public function test_finalized_inventory_cannot_be_replaced_by_another_request(): void
    {
        $payload = $this->payload();
        $this->save($payload);
        $payload['request_id'] = (string) Str::uuid();
        $this->assertSame('INVENTARIO_CONFLICTO', $this->save($payload)['code']);
        $this->assertDatabaseCount('stock_control_inventario', 1);
    }

    public function test_permissions_are_individual_and_rejection_is_idempotent(): void
    {
        DB::table('permisosusuariospos')->where('actionvista', 2)->delete();
        $payload = $this->payload();
        $response = $this->save($payload);
        $this->assertSame('SIN_ACCESO', $response['code']);
        $this->assertSame($response, $this->save($payload));
        $this->assertDatabaseCount('stock_control_inventario', 0);
    }

    public function test_other_stock_is_rejected_without_saving(): void
    {
        $payload = $this->payload();
        $payload['stock_id'] = '2';
        try {
            $this->save($payload);
            $this->fail('Otro stock');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('stock_control_inventario', 0);
    }

    public function test_incomplete_counts_duplicate_lots_and_wrong_differences_cannot_be_uploaded(): void
    {
        foreach (['missing', 'difference', 'duplicate', 'empty', 'letter'] as $case) {
            $payload = $this->payload();
            if ($case === 'missing') {
                $payload['letras'][0]['productos'][0]['cantidad_conteo'] = null;
            } elseif ($case === 'difference') {
                $payload['letras'][0]['productos'][0]['diferencia'] = '0';
            } elseif ($case === 'duplicate') {
                $payload['letras'][] = ['letra' => 'B', 'productos' => $payload['letras'][0]['productos']];
            } elseif ($case === 'empty') {
                $payload['letras'] = [];
            } else {
                $payload['letras'][0]['letra'] = 'a';
            }
            try {
                $this->save($payload);
                $this->fail('Conteo invalido: '.$case);
            } catch (ValidationException|HttpException $error) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('stock_control_inventario', 0);
        $this->assertDatabaseCount('stock_control_inventario_solicitudes', 0);
    }
}
