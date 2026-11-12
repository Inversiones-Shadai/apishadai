<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'frm')->lockForUpdate()->value('id');
            $state = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($module === null || $state === null) {
                throw new LogicException('Falta Farma o el estado de vistas.');
            }

            $view = DB::table('vistas')->where('codigo', 'avt')->first();
            if ($view && (string) $view->modulo !== (string) $module) {
                throw new LogicException('El código avt pertenece a otro módulo.');
            }
            $id = $view?->id ?? DB::table('vistas')->insertGetId([
                'codigo' => 'avt', 'nombre' => 'Alertas de venta', 'modulo' => $module,
                'estado' => $state, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['avtbtn01' => 'Ver alertas de venta', 'avtbtn02' => 'Administrar alertas de venta'] as $code => $name) {
                $action = DB::table('actionsvistas')->where('codigo', $code)->first();
                if ($action && (string) $action->vista !== (string) $id) {
                    throw new LogicException('La acción pertenece a otra vista: '.$code);
                }
                if (! $action) {
                    DB::table('actionsvistas')->insert([
                        'codigo' => $code, 'nombre' => $name, 'vista' => $id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }, 3);
    }

    public function down(): void
    {
        // Preserve IDs and existing grants.
    }
};
