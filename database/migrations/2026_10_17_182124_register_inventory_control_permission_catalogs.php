<?php

use Database\Seeders\InventoryControlPermissionCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['modulos', 'vistas', 'vista_estados', 'actionsvistas', 'modulospos', 'vistaspos', 'botonespos'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException('Falta el catálogo de permisos: '.$table);
            }
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (['modulospos', 'vistaspos', 'botonespos', 'vistas', 'actionsvistas', 'sync_changes', 'sync_entity_versions', 'hq_outbox'] as $table) {
                $engine = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())
                    ->where('table_name', $table)->value('engine');
                if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                    throw new LogicException('El catálogo y publicación requieren InnoDB: '.$table);
                }
            }
        }
        (new InventoryControlPermissionCatalogSeeder)->run();
    }

    public function down(): void
    {
        // Preserve catalog IDs, user assignments and publication history.
    }
};
