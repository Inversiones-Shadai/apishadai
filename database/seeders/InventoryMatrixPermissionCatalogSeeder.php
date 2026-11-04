<?php

namespace Database\Seeders;

use App\Models\Pos\BotonPos;
use App\Models\Pos\ModuloPos;
use App\Models\Pos\VistaPos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryMatrixPermissionCatalogSeeder extends Seeder
{
    public const MODULE = 'inventario';

    public const VIEW = 'inventario.matriz';

    public const ACTIONS = [
        'matriz.ver' => 'Ver Matriz de inventario',
        'matriz.filtrar' => 'Filtrar Matriz de inventario',
        'matriz.modificar' => 'Modificar matriz de inventario',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $module = ModuloPos::where('codigo', self::MODULE)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::MODULE], ['nombre' => 'Inventario', 'activo' => true]);
            $view = VistaPos::where('codigo', self::VIEW)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::VIEW], ['modulo_id' => $module->id, 'nombre' => 'Matriz de inventario', 'activo' => true]);
            if ((string) $view->modulo_id !== (string) $module->id) {
                throw new LogicException('La vista de Matriz de inventario pertenece a otro módulo.');
            }
            foreach (self::ACTIONS as $code => $name) {
                $button = BotonPos::where('codigo', $code)->lockForUpdate()->firstOrCreate(
                    ['codigo' => $code], ['vista_id' => $view->id, 'nombre' => $name, 'activo' => true]);
                if ((string) $button->vista_id !== (string) $view->id) {
                    throw new LogicException('La acción de Matriz de inventario pertenece a otra vista.');
                }
            }
            // Metadata only: never create permissions for a user or infer them from a role.
        }, 3);
    }
}
