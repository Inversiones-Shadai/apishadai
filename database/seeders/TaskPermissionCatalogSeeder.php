<?php

namespace Database\Seeders;

use App\Models\Pos\BotonPos;
use App\Models\Pos\ModuloPos;
use App\Models\Pos\VistaPos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class TaskPermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $module = ModuloPos::firstOrCreate(['codigo' => 'tareas'], ['nombre' => 'Tareas', 'activo' => true]);
            $view = VistaPos::firstOrCreate(['codigo' => 'tareas.asignadas'], ['modulo_id' => $module->id, 'nombre' => 'Mis tareas', 'activo' => true]);
            if ((string) $view->modulo_id !== (string) $module->id) {
                throw new LogicException('La vista de tareas pertenece a otro módulo.');
            }
            foreach (['tarea.ver' => 'Ver mis tareas y contador', 'tarea.estado' => 'Cambiar estado de mis tareas'] as $code => $name) {
                $button = BotonPos::firstOrCreate(['codigo' => $code], ['vista_id' => $view->id, 'nombre' => $name, 'activo' => true]);
                if ((string) $button->vista_id !== (string) $view->id) {
                    throw new LogicException('El permiso de tareas pertenece a otra vista.');
                }
            }
            $moduleId = DB::table('modulos')->where('codigo', 'frm')->lockForUpdate()->value('id');
            $state = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($moduleId === null || $state === null) {
                throw new LogicException('Falta Farma o el estado ACTIVO.');
            }
            $portal = DB::table('vistas')->where('codigo', 'tar')->lockForUpdate()->first();
            if ($portal && (string) $portal->modulo !== (string) $moduleId) {
                throw new LogicException('La vista tar pertenece a otro módulo.');
            }
            $portalId = $portal?->id ?? DB::table('vistas')->insertGetId(['codigo' => 'tar', 'nombre' => 'Tareas',
                'modulo' => $moduleId, 'estado' => $state, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([1 => 'Listar tareas', 2 => 'Crear y asignar tareas', 3 => 'Ver detalle de tareas',
                4 => 'Modificar y reasignar tareas', 5 => 'Cancelar tareas', 6 => 'Filtrar tareas'] as $number => $name) {
                $code = 'tarbtn0'.$number;
                $action = DB::table('actionsvistas')->where('codigo', $code)->lockForUpdate()->first();
                if ($action && (string) $action->vista !== (string) $portalId) {
                    throw new LogicException('La acción de tareas pertenece a otra vista.');
                }
                if (! $action) {
                    DB::table('actionsvistas')->insert(['codigo' => $code, 'nombre' => $name, 'vista' => $portalId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }, 3);
    }
}
