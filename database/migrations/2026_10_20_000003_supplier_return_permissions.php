<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VIEWS = [
        'vnc' => ['Próximos a vencer', ['vncbtn01' => 'Buscar productos próximos a vencer', 'vncbtn02' => 'Consultar y seleccionar lotes', 'vncbtn03' => 'Verificar reglas de devolución de lotes']],
        'rdp' => ['Reglas de proveedores', ['rdpbtn01' => 'Consultar reglas de devolución de proveedores', 'rdpbtn02' => 'Guardar reglas de devolución de proveedores']],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'frm')->value('id');
            $active = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($module === null || $active === null) {
                throw new LogicException('Falta el módulo Farma o el estado ACTIVO.');
            }
            foreach (self::VIEWS as $code => [$name, $actions]) {
                $view = DB::table('vistas')->where('codigo', $code)->first();
                if ($view !== null && (int) $view->modulo !== (int) $module) {
                    throw new LogicException('La vista '.$code.' pertenece a otro módulo.');
                }
                $id = $view?->id ?? DB::table('vistas')->insertGetId([
                    'codigo' => $code, 'nombre' => $name, 'modulo' => $module,
                    'estado' => $active, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($actions as $actionCode => $actionName) {
                    $action = DB::table('actionsvistas')->where('codigo', $actionCode)->first();
                    if ($action !== null && (int) $action->vista !== (int) $id) {
                        throw new LogicException('La acción '.$actionCode.' pertenece a otra vista.');
                    }
                    if ($action === null) {
                        DB::table('actionsvistas')->insert(['codigo' => $actionCode, 'nombre' => $actionName, 'vista' => $id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            // Register the catalog only. User assignments remain explicit.
        });
    }

    public function down(): void
    {
        foreach (self::VIEWS as $code => [, $actions]) {
            $ids = DB::table('actionsvistas')->whereIn('codigo', array_keys($actions))->pluck('id');
            if (Schema::hasTable('permisos')) {
                $ids = $ids->diff(DB::table('permisos')->whereIn('actionvista', $ids)->pluck('actionvista'));
            }
            DB::table('actionsvistas')->whereIn('id', $ids)->delete();
            $view = DB::table('vistas')->where('codigo', $code)->first();
            if ($view !== null && ! DB::table('actionsvistas')->where('vista', $view->id)->exists()
                && (! Schema::hasTable('permisos') || ! DB::table('permisos')->where('vista', $view->id)->exists())) {
                DB::table('vistas')->where('id', $view->id)->delete();
            }
        }
    }
};
