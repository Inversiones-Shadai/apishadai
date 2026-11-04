<?php

namespace Tests\Feature\Pos;

use Database\Seeders\PrinterPermissionCatalogSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrinterPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'headquarters.publishing_enabled' => false]);
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
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_registers_individual_actions_without_assigning_users_and_preserves_inactive_metadata(): void
    {
        $migration = require database_path('migrations/2026_10_27_000002_pos_printer_settings_permissions.php');
        $migration->up();
        $ids = DB::table('botonespos')->orderBy('id')->pluck('id')->all();
        DB::table('botonespos')->where('codigo', 'impresoras.probar')->update(['activo' => false]);
        $migration->up();
        $module = DB::table('modulospos')->where('codigo', 'pos')->sole();
        $view = DB::table('vistaspos')->where('codigo', 'pos.impresoras')->sole();
        $this->assertSame($module->id, $view->modulo_id);
        $this->assertSame($ids, DB::table('botonespos')->orderBy('id')->pluck('id')->all());
        $this->assertSame(array_keys(PrinterPermissionCatalogSeeder::ACTIONS), DB::table('botonespos')->orderBy('id')->pluck('codigo')->all());
        $this->assertEquals(0, DB::table('botonespos')->where('codigo', 'impresoras.probar')->value('activo'));
        $this->assertSame(0, DB::table('permisosusuariospos')->count());
        $migration->down();
        $this->assertSame(3, DB::table('botonespos')->count());
    }

    public function test_wrong_parent_rolls_back_partial_catalog_creation(): void
    {
        $other = DB::table('modulospos')->insertGetId(['codigo' => 'TEST', 'nombre' => 'TEST', 'activo' => true]);
        DB::table('vistaspos')->insert(['codigo' => 'pos.impresoras', 'modulo_id' => $other, 'nombre' => 'TEST', 'activo' => true]);
        try {
            (new PrinterPermissionCatalogSeeder)->run();
            $this->fail('Expected wrong-parent rejection.');
        } catch (\LogicException) {
            $this->assertSame(0, DB::table('botonespos')->count());
            $this->assertFalse(DB::table('modulospos')->where('codigo', 'pos')->exists());
        }
    }

    public function test_rejects_action_assigned_to_a_different_view(): void
    {
        DB::table('botonespos')->insert(['codigo' => 'impresoras.probar', 'vista_id' => 999, 'nombre' => 'TEST', 'activo' => true]);
        $this->expectException(\LogicException::class);
        (new PrinterPermissionCatalogSeeder)->run();
    }
}
