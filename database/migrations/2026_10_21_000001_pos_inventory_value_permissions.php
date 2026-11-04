<?php

use Database\Seeders\InventoryValuePermissionCatalogSeeder;
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
        (new InventoryValuePermissionCatalogSeeder)->run();
    }

    public function down(): void
    {
        // Preserve catalog IDs, user assignment history and HQ publication history.
    }
};
