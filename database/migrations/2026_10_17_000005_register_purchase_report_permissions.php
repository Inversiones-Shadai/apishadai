<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'frm')->value('id');
            $active = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($module === null || $active === null) {
                throw new LogicException('Falta el módulo Farma o el estado ACTIVO.');
            }
            $view = DB::table('vistas')->where('codigo', 'rcp')->first();
            if ($view !== null && (int) $view->modulo !== (int) $module) {
                throw new LogicException('El código rcp pertenece a otro módulo.');
            }
            $viewId = $view?->id ?? DB::table('vistas')->insertGetId([
                'codigo' => 'rcp', 'nombre' => 'reporte-compras', 'modulo' => $module,
                'estado' => $active, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['rcpbtn01' => 'Listar y filtrar reporte de compras', 'rcpbtn02' => 'Consultar proveedores del reporte de compras'] as $code => $name) {
                $action = DB::table('actionsvistas')->where('codigo', $code)->first();
                if ($action !== null && (int) $action->vista !== (int) $viewId) {
                    throw new LogicException('El código '.$code.' pertenece a otra vista.');
                }
                if ($action === null) {
                    DB::table('actionsvistas')->insert(['codigo' => $code, 'nombre' => $name, 'vista' => $viewId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('actionsvistas')->whereIn('codigo', ['rcpbtn01', 'rcpbtn02'])->pluck('id');
        if (Schema::hasTable('permisos')) {
            $ids = $ids->diff(DB::table('permisos')->whereIn('actionvista', $ids)->pluck('actionvista'));
        }
        DB::table('actionsvistas')->whereIn('id', $ids)->delete();
        $view = DB::table('vistas')->where('codigo', 'rcp')->first();
        if ($view !== null && ! DB::table('actionsvistas')->where('vista', $view->id)->exists()
            && (! Schema::hasTable('permisos') || ! DB::table('permisos')->where('vista', $view->id)->exists())) {
            DB::table('vistas')->where('id', $view->id)->delete();
        }
    }
};
