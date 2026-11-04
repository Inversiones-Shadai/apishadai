<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $view = DB::table('vistas')->join('modulos', 'vistas.modulo', '=', 'modulos.id')
                ->where('vistas.codigo', 'rcp')->where('modulos.codigo', 'frm')->value('vistas.id');
            if ($view === null) {
                throw new LogicException('Falta la vista del reporte de compras de Farma.');
            }
            $action = DB::table('actionsvistas')->where('codigo', 'rcpbtn03')->first();
            if ($action !== null && (int) $action->vista !== (int) $view) {
                throw new LogicException('El permiso rcpbtn03 pertenece a otra vista.');
            }
            if ($action === null) {
                DB::table('actionsvistas')->insert(['codigo' => 'rcpbtn03', 'nombre' => 'Ver pagos de la compra',
                    'vista' => $view, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        $id = DB::table('actionsvistas')->where('codigo', 'rcpbtn03')->value('id');
        if ($id !== null && ! DB::table('permisos')->where('actionvista', $id)->exists()) {
            DB::table('actionsvistas')->where('id', $id)->delete();
        }
    }
};
