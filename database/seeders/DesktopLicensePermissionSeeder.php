<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DesktopLicensePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'cfg')->value('id');
            $active = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if (! $module || ! $active) {
                throw new LogicException('Falta Config o el estado ACTIVO de vistas.');
            }
            $view = DB::table('vistas')->where('codigo', 'lic')->first();
            if ($view && (string) $view->modulo !== (string) $module) {
                throw new LogicException('El código lic ya pertenece a otro módulo.');
            }
            $id = $view?->id ?? DB::table('vistas')->insertGetId([
                'codigo' => 'lic', 'nombre' => 'Licencias de escritorio', 'modulo' => $module, 'estado' => $active,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['licbtn01' => 'Listar licencias', 'licbtn02' => 'Generar licencias', 'licbtn03' => 'Modificar y suspender licencias', 'licbtn04' => 'Ver vinculaciones y validaciones', 'licbtn05' => 'Desvincular equipos', 'licbtn06' => 'Eliminar licencias desvinculadas'] as $code => $name) {
                $action = DB::table('actionsvistas')->where('codigo', $code)->first();
                if ($action && (string) $action->vista !== (string) $id) {
                    throw new LogicException('El código de acción de licencias ya está utilizado.');
                }
                if (! $action) {
                    DB::table('actionsvistas')->insert(['codigo' => $code, 'nombre' => $name, 'vista' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
