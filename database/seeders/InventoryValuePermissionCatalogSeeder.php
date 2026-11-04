<?php

namespace Database\Seeders;

use App\Models\Pos\BotonPos;
use App\Models\Pos\ModuloPos;
use App\Models\Pos\VistaPos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryValuePermissionCatalogSeeder extends Seeder
{
    public const MODULE = 'administracion';

    public const VIEW = 'administracion.reporte.valor_inventario';

    public const ACTIONS = [
        'reporte.valor_inventario.ver' => 'Ver valor de inventario',
        'reporte.valor_inventario.filtrar' => 'Filtrar valor de inventario',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $module = ModuloPos::where('codigo', self::MODULE)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::MODULE], ['nombre' => 'Administración', 'activo' => true]);
            $view = VistaPos::where('codigo', self::VIEW)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::VIEW], ['modulo_id' => $module->id, 'nombre' => 'Reportes · Valor de inventario', 'activo' => true]);
            if ((string) $view->modulo_id !== (string) $module->id) {
                throw new LogicException('La vista del valor de inventario pertenece a otro módulo.');
            }
            foreach (self::ACTIONS as $code => $name) {
                $button = BotonPos::where('codigo', $code)->lockForUpdate()->firstOrCreate(
                    ['codigo' => $code], ['vista_id' => $view->id, 'nombre' => $name, 'activo' => true]);
                if ((string) $button->vista_id !== (string) $view->id) {
                    throw new LogicException('La acción del valor de inventario pertenece a otra vista.');
                }
            }
            // Metadata only: never create permissions for a user or infer them from a role.
        }, 3);
    }
}
