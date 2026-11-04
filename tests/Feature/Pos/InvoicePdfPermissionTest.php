<?php

namespace Tests\Feature\Pos;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoicePdfPermissionTest extends TestCase
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
        $module = DB::table('modulospos')->insertGetId(['codigo' => 'pos', 'nombre' => 'POS', 'activo' => true]);
        DB::table('vistaspos')->insert(['codigo' => 'pos.facturas', 'nombre' => 'Facturas', 'activo' => true, 'modulo_id' => $module]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_registers_pdf_action_idempotently_and_preserves_revocation_without_assignments(): void
    {
        $migration = require database_path('migrations/2026_10_27_000001_add_invoice_pdf_export_permission.php');
        $migration->up();
        $button = DB::table('botonespos')->sole();
        $this->assertSame('factura.exportar_pdf', $button->codigo);
        $this->assertSame(DB::table('vistaspos')->sole()->id, $button->vista_id);
        DB::table('botonespos')->where('id', $button->id)->update(['activo' => false]);
        $migration->up();
        $this->assertSame($button->id, DB::table('botonespos')->sole()->id);
        $this->assertEquals(0, DB::table('botonespos')->sole()->activo);
        $this->assertSame(0, DB::table('permisosusuariospos')->count());
    }

    public function test_rejects_an_action_attached_to_another_view(): void
    {
        DB::table('botonespos')->insert(['codigo' => 'factura.exportar_pdf', 'vista_id' => 999, 'nombre' => 'TEST', 'activo' => true]);
        $this->expectException(\LogicException::class);
        (require database_path('migrations/2026_10_27_000001_add_invoice_pdf_export_permission.php'))->up();
    }
}
