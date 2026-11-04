<?php

namespace Database\Seeders;

use App\Models\Pos\BotonPos;
use App\Models\Pos\ModuloPos;
use App\Models\Pos\VistaPos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryControlPermissionCatalogSeeder extends Seeder
{
    public const MODULE = 'inventario';

    public const VIEW = 'inventario.control';

    public const PORTAL_VIEW = 'civ';

    public const PORTAL_ACTIONS = [
        'civbtn01' => 'Listar controles de inventario',
        'civbtn02' => 'Ver detalle de control de inventario',
        'civbtn03' => 'Filtrar controles de inventario',
    ];

    public const ACTIONS = [
        'control.ver' => 'Ver Control de inventario',
        'control.crear' => 'Crear inventario',
        'control.finalizar' => 'Finalizar inventario',
        'control.modificar' => 'Modificar control de inventario',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $module = ModuloPos::where('codigo', self::MODULE)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::MODULE], ['nombre' => 'Inventario', 'activo' => true]);
            $view = VistaPos::where('codigo', self::VIEW)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::VIEW], ['modulo_id' => $module->id, 'nombre' => 'Control de inventario', 'activo' => true]);
            if ((string) $view->modulo_id !== (string) $module->id) {
                throw new LogicException('La vista de Control de inventario pertenece a otro módulo.');
            }
            foreach (self::ACTIONS as $code => $name) {
                $button = BotonPos::where('codigo', $code)->lockForUpdate()->firstOrCreate(
                    ['codigo' => $code], ['vista_id' => $view->id, 'nombre' => $name, 'activo' => true]);
                if ((string) $button->vista_id !== (string) $view->id) {
                    throw new LogicException('La acción de Control de inventario pertenece a otra vista.');
                }
            }
            $portalModule = DB::table('modulos')->where('codigo', 'frm')->lockForUpdate()->value('id');
            $active = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($portalModule === null || $active === null) {
                throw new LogicException('Falta Farma o el estado ACTIVO de vistas.');
            }
            $portalView = DB::table('vistas')->where('codigo', self::PORTAL_VIEW)->lockForUpdate()->first();
            if ($portalView && (string) $portalView->modulo !== (string) $portalModule) {
                throw new LogicException('La vista de Control de inventario de ShAdmin pertenece a otro módulo.');
            }
            $portalId = $portalView?->id ?? DB::table('vistas')->insertGetId([
                'codigo' => self::PORTAL_VIEW, 'nombre' => 'Control de inventario', 'modulo' => $portalModule,
                'estado' => $active, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (self::PORTAL_ACTIONS as $code => $name) {
                $action = DB::table('actionsvistas')->where('codigo', $code)->lockForUpdate()->first();
                if ($action && (string) $action->vista !== (string) $portalId) {
                    throw new LogicException('La acción de Control de inventario de ShAdmin pertenece a otra vista.');
                }
                if (! $action) {
                    DB::table('actionsvistas')->insert([
                        'codigo' => $code, 'nombre' => $name, 'vista' => $portalId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            // Metadata only: never create permissions for a user or infer them from a role.
        }, 3);
    }
}
