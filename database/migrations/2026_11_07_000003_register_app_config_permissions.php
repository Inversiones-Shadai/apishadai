<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'cfg')->lockForUpdate()->value('id');
            $state = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($module === null || $state === null) {
                throw new LogicException('Falta Config o el estado ACTIVO de vistas.');
            }

            $view = DB::table('vistas')->where('codigo', 'acf')->first();
            if ($view && (string) $view->modulo !== (string) $module) {
                throw new LogicException('El código acf pertenece a otro módulo.');
            }
            $id = $view?->id ?? DB::table('vistas')->insertGetId([
                'codigo' => 'acf', 'nombre' => 'Configuración de aplicación', 'modulo' => $module,
                'estado' => $state, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['acfbtn01' => 'Consultar configuración de aplicación', 'acfbtn02' => 'Crear configuración de aplicación', 'acfbtn03' => 'Editar configuración de aplicación'] as $code => $name) {
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
        // Preserve catalog IDs and grants assigned after deployment.
    }
};
