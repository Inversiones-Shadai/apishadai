<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $view = DB::table('vistas')->join('modulos', 'vistas.modulo', '=', 'modulos.id')
                ->where('vistas.codigo', 'prd')->where('modulos.codigo', 'frm')->value('vistas.id');
            if ($view === null) {
                throw new LogicException('Falta la vista de productos de Farma.');
            }
            $action = DB::table('actionsvistas')->where('codigo', 'prdbtn19')->first();
            if ($action !== null && (int) $action->vista !== (int) $view) {
                throw new LogicException('El permiso prdbtn19 pertenece a otra vista.');
            }
            if ($action !== null && $action->nombre !== 'Convertir producto padre a hijo') {
                throw new LogicException('El código prdbtn19 ya identifica otra acción.');
            }
            if ($action === null) {
                DB::table('actionsvistas')->insert([
                    'codigo' => 'prdbtn19', 'nombre' => 'Convertir producto padre a hijo',
                    'vista' => $view, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        $id = DB::table('actionsvistas')->where('codigo', 'prdbtn19')
            ->where('nombre', 'Convertir producto padre a hijo')->value('id');
        if ($id !== null && ! DB::table('permisos')->where('actionvista', $id)->exists()) {
            DB::table('actionsvistas')->where('id', $id)->delete();
        }
    }
};
