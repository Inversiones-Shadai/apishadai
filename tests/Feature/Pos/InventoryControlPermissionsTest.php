<?php

namespace Tests\Feature\Pos;

use Database\Seeders\InventoryControlPermissionCatalogSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class InventoryControlPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'headquarters.publishing_enabled' => false]);
        DB::purge('sqlite');
        foreach (['modulospos', 'vistaspos', 'botonespos'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('codigo')->unique();
                $table->string('nombre');
                $table->boolean('activo');
                if ($name === 'vistaspos') {
                    $table->unsignedBigInteger('modulo_id');
                }
                if ($name === 'botonespos') {
                    $table->unsignedBigInteger('vista_id');
                }
                $table->timestamps();
            });
        }
        Schema::create('permisosusuariospos', fn (Blueprint $table) => $table->id());
        Schema::create('permisos', fn (Blueprint $table) => $table->id());
        Schema::create('modulos', fn (Blueprint $t) => [$t->id(), $t->string('codigo')->unique()]);
        Schema::create('vista_estados', fn (Blueprint $t) => [$t->id(), $t->string('descripcion')]);
        Schema::create('vistas', fn (Blueprint $t) => [$t->id(), $t->string('codigo')->unique(), $t->string('nombre'), $t->integer('modulo'), $t->integer('estado'), $t->timestamps()]);
        Schema::create('actionsvistas', fn (Blueprint $t) => [$t->id(), $t->string('codigo')->unique(), $t->string('nombre'), $t->integer('vista'), $t->timestamps()]);
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm']);
        DB::table('vista_estados')->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_registers_exact_hierarchy_idempotently_without_user_grants(): void
    {
        $migration = require database_path('migrations/2026_10_17_182124_register_inventory_control_permission_catalogs.php');
        $migration->up();
        $portalIds = DB::table('actionsvistas')->pluck('id')->all();
        $ids = DB::table('botonespos')->orderBy('id')->pluck('id')->all();
        $migration->up();
        $this->assertSame($ids, DB::table('botonespos')->orderBy('id')->pluck('id')->all());
        $this->assertSame($portalIds, DB::table('actionsvistas')->pluck('id')->all());
        $module = DB::table('modulospos')->where('codigo', 'inventario')->first();
        $view = DB::table('vistaspos')->where('codigo', 'inventario.control')->first();
        $this->assertSame($module->id, $view->modulo_id);
        $this->assertSame(array_keys(InventoryControlPermissionCatalogSeeder::ACTIONS),
            DB::table('botonespos')->where('vista_id', $view->id)->orderBy('id')->pluck('codigo')->all());
        $this->assertSame(0, DB::table('permisosusuariospos')->count());
        $migration->down();
        $this->assertSame(4, DB::table('botonespos')->count());
        $portal = DB::table('vistas')->where('codigo', 'civ')->first();
        $this->assertSame(1, $portal->modulo);
        $this->assertSame(array_keys(InventoryControlPermissionCatalogSeeder::PORTAL_ACTIONS),
            DB::table('actionsvistas')->where('vista', $portal->id)->orderBy('id')->pluck('codigo')->all());
        $this->assertSame(0, DB::table('permisos')->count());
    }

    public function test_wrong_parent_rolls_back_partial_catalog_creation(): void
    {
        $other = DB::table('modulospos')->insertGetId(['codigo' => 'TEST', 'nombre' => 'TEST', 'activo' => true]);
        DB::table('vistaspos')->insert(['codigo' => 'inventario.control', 'nombre' => 'TEST', 'activo' => true, 'modulo_id' => $other]);
        try {
            (new InventoryControlPermissionCatalogSeeder)->run();
            $this->fail('Expected wrong-parent rejection.');
        } catch (LogicException) {
            $this->assertFalse(DB::table('modulospos')->where('codigo', 'inventario')->exists());
            $this->assertSame(0, DB::table('botonespos')->count());
        }
    }

    public function test_existing_inactive_metadata_and_ids_are_preserved(): void
    {
        (new InventoryControlPermissionCatalogSeeder)->run();
        DB::table('botonespos')->where('codigo', 'control.crear')->update(['activo' => false]);
        DB::table('vistas')->where('codigo', 'civ')->update(['estado' => 9]);
        $ids = DB::table('botonespos')->pluck('id')->all();
        (new InventoryControlPermissionCatalogSeeder)->run();
        $this->assertSame($ids, DB::table('botonespos')->pluck('id')->all());
        $this->assertSame(9, DB::table('vistas')->where('codigo', 'civ')->value('estado'));
        $this->assertEquals(0, DB::table('botonespos')->where('codigo', 'control.crear')->value('activo'));
    }

    public function test_conflicting_portal_action_rolls_back_both_catalogs(): void
    {
        DB::table('actionsvistas')->insert(['codigo' => 'civbtn02', 'nombre' => 'TEST', 'vista' => 99]);
        try {
            (new InventoryControlPermissionCatalogSeeder)->run();
            $this->fail('Expected conflicting portal action rejection.');
        } catch (LogicException) {
            $this->assertSame(0, DB::table('modulospos')->count());
            $this->assertSame(0, DB::table('vistas')->count());
            $this->assertSame(1, DB::table('actionsvistas')->count());
        }
    }
}
