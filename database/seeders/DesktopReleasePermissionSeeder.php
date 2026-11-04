<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DesktopReleasePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'cfg')->value('id');
            $active = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if (! $module || ! $active) {
                throw new LogicException('Falta Config o el estado ACTIVO de vistas.');
            }
            $view = DB::table('vistas')->where('codigo', 'upd')->first();
            if ($view && (string) $view->modulo !== (string) $module) {
                throw new LogicException('El código upd ya pertenece a otro módulo.');
            }
            $id = $view?->id ?? DB::table('vistas')->insertGetId(['codigo' => 'upd', 'nombre' => 'Actualizaciones de escritorio',
                'modulo' => $module, 'estado' => $active, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['updbtn01' => 'Listar versiones', 'updbtn02' => 'Crear borradores', 'updbtn03' => 'Editar borradores',
                'updbtn04' => 'Asignar equipos piloto', 'updbtn05' => 'Publicar en piloto', 'updbtn06' => 'Promover a estable',
                'updbtn07' => 'Retirar versiones', 'updbtn08' => 'Consultar auditoría'] as $code => $name) {
                $action = DB::table('actionsvistas')->where('codigo', $code)->first();
                if ($action && (string) $action->vista !== (string) $id) {
                    throw new LogicException('Código de permiso de actualizaciones ya utilizado.');
                }
                if (! $action) {
                    DB::table('actionsvistas')->insert(['codigo' => $code, 'nombre' => $name, 'vista' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
