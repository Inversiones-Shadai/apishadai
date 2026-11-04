<?php

use Database\Seeders\TaskPermissionCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['modulospos', 'vistaspos', 'botonespos', 'sync_changes', 'sync_entity_versions', 'hq_outbox'] as $table) {
                $engine = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->value('engine');
                if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                    throw new LogicException('Los permisos y su publicación HQ requieren InnoDB: '.$table);
                }
            }
        }
        (new TaskPermissionCatalogSeeder)->run();
    }

    public function down(): void {}
};
