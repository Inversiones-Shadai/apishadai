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
                throw new LogicException('Falta Farma o el estado ACTIVO.');
            }
            $view = DB::table('vistas')->where('codigo', 'rct')->first();
            if ($view !== null && (int) $view->modulo !== (int) $module) {
                throw new LogicException('El código rct pertenece a otro módulo.');
            }
            $id = $view?->id ?? DB::table('vistas')->insertGetId(['codigo' => 'rct', 'nombre' => 'Costeo de productos',
                'modulo' => $module, 'estado' => $active, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['rctbtn01' => 'Consultar reporte de costeo de productos', 'rctbtn02' => 'Buscar productos del reporte de costeo'] as $code => $name) {
                $action = DB::table('actionsvistas')->where('codigo', $code)->first();
                if ($action !== null && (int) $action->vista !== (int) $id) {
                    throw new LogicException('La acción '.$code.' pertenece a otra vista.');
                }
                if ($action === null) {
                    DB::table('actionsvistas')->insert(['codigo' => $code, 'nombre' => $name, 'vista' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('actionsvistas')->whereIn('codigo', ['rctbtn01', 'rctbtn02'])->pluck('id');
        if (Schema::hasTable('permisos')) {
            $ids = $ids->diff(DB::table('permisos')->whereIn('actionvista', $ids)->pluck('actionvista'));
        }
        DB::table('actionsvistas')->whereIn('id', $ids)->delete();
        $view = DB::table('vistas')->where('codigo', 'rct')->first();
        if ($view !== null && ! DB::table('actionsvistas')->where('vista', $view->id)->exists()
            && (! Schema::hasTable('permisos') || ! DB::table('permisos')->where('vista', $view->id)->exists())) {
            DB::table('vistas')->where('id', $view->id)->delete();
        }
    }
};
