<?php

use Database\Seeders\PrinterPermissionCatalogSeeder;
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
            foreach (['modulospos', 'vistaspos', 'botonespos', 'sync_changes', 'sync_entity_versions', 'hq_outbox'] as $table) {
                $engine = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())
                    ->where('table_name', $table)->value('engine');
                if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                    throw new LogicException('El catálogo y publicación requieren InnoDB: '.$table);
                }
            }
        }
        (new PrinterPermissionCatalogSeeder)->run();
    }

    public function down(): void
    {
        // Preserve identifiers, assignments and publication history.
    }
};
