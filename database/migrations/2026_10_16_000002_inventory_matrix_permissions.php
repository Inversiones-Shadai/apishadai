<?php

use Database\Seeders\InventoryMatrixPermissionCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['modulospos', 'vistaspos', 'botonespos'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException('Falta el catálogo de permisos POS: '.$table);
            }
        }
        if (DB::getDriverName() === 'mysql') {
            // Catalog writes and their existing HQ outbox must commit/rollback together.
            foreach (['modulospos', 'vistaspos', 'botonespos', 'sync_changes', 'sync_entity_versions', 'hq_outbox'] as $table) {
                $engine = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())
                    ->where('table_name', $table)->value('engine');
                if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                    throw new LogicException('El catálogo y publicación requieren InnoDB: '.$table);
                }
            }
        }
        (new InventoryMatrixPermissionCatalogSeeder)->run();
        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'frm')->lockForUpdate()->value('id');
            $state = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($module === null || $state === null) {
                throw new LogicException('Falta Farma o el estado de vistas.');
            }
            $view = DB::table('vistas')->where('codigo', 'miv')->lockForUpdate()->first();
            if ($view && (string) $view->modulo !== (string) $module) {
                throw new LogicException('La vista miv pertenece a otro módulo.');
            }
            $id = $view?->id ?? DB::table('vistas')->insertGetId(['codigo' => 'miv', 'nombre' => 'Matriz de inventario',
                'modulo' => $module, 'estado' => $state, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([1 => 'Ver matriz de inventario', 2 => 'Filtrar matriz de inventario'] as $number => $name) {
                $code = 'mivbtn0'.$number;
                $action = DB::table('actionsvistas')->where('codigo', $code)->lockForUpdate()->first();
                if ($action && (string) $action->vista !== (string) $id) {
                    throw new LogicException('La acción de matriz pertenece a otra vista.');
                }
                if (! $action) {
                    DB::table('actionsvistas')->insert(['codigo' => $code, 'nombre' => $name, 'vista' => $id,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }, 3);
    }

    public function down(): void
    {
        // Preserve catalog IDs, user assignment history and HQ publication history.
    }
};
