<?php

use App\Models\Pos\BotonPos;
use App\Models\Pos\ModuloPos;
use App\Models\Pos\VistaPos;
use App\Services\Headquarters\ChangeRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $view = VistaPos::where('codigo', 'pos.facturas')->lockForUpdate()->firstOrFail();
            $module = ModuloPos::findOrFail($view->modulo_id);
            if ($module->codigo !== 'pos') {
                throw new LogicException('La vista Facturas pertenece a otro módulo.');
            }
            $recorder = app(ChangeRecorder::class);
            $recorder->record($module, 'UPDATE');
            $recorder->record($view, 'UPDATE');
            $button = BotonPos::firstOrCreate(['codigo' => 'factura.exportar_pdf'], [
                'vista_id' => $view->id, 'nombre' => 'Exportar facturas a PDF', 'activo' => true,
            ]);
            if ($button->vista_id !== $view->id) {
                throw new LogicException('La acción Exportar facturas a PDF pertenece a otra vista.');
            }
            if (! $button->wasRecentlyCreated) {
                $recorder->record($button, 'UPDATE');
            }
            // Registration never assigns permissions to users or roles.
        });
    }

    public function down(): void
    {
        throw new RuntimeException('El catálogo de permisos POS y su historial se conservan.');
    }
};
