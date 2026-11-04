<?php

namespace Database\Seeders;

use App\Models\Pos\BotonPos;
use App\Models\Pos\ModuloPos;
use App\Models\Pos\VistaPos;
use App\Services\Headquarters\ChangeRecorder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class PrinterPermissionCatalogSeeder extends Seeder
{
    public const MODULE = 'pos';

    public const VIEW = 'pos.impresoras';

    public const ACTIONS = [
        'impresoras.ver' => 'Ver configuración de impresoras',
        'impresoras.configurar' => 'Configurar impresoras del equipo',
        'impresoras.probar' => 'Imprimir prueba de impresoras',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $module = ModuloPos::where('codigo', self::MODULE)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::MODULE], ['nombre' => 'POS', 'activo' => true]);
            $view = VistaPos::where('codigo', self::VIEW)->lockForUpdate()->firstOrCreate(
                ['codigo' => self::VIEW], ['modulo_id' => $module->id, 'nombre' => 'Impresoras', 'activo' => true]);
            if ((string) $view->modulo_id !== (string) $module->id) {
                throw new LogicException('La vista Impresoras pertenece a otro módulo.');
            }
            $recorder = app(ChangeRecorder::class);
            $recorder->record($module, 'UPDATE');
            $recorder->record($view, 'UPDATE');
            foreach (self::ACTIONS as $code => $name) {
                $button = BotonPos::where('codigo', $code)->lockForUpdate()->firstOrCreate(
                    ['codigo' => $code], ['vista_id' => $view->id, 'nombre' => $name, 'activo' => true]);
                if ((string) $button->vista_id !== (string) $view->id) {
                    throw new LogicException('La acción de impresoras pertenece a otra vista.');
                }
                if (! $button->wasRecentlyCreated) {
                    $recorder->record($button, 'UPDATE');
                }
            }
            // Preserve existing inactive metadata; never assign users or roles.
        }, 3);
    }
}
