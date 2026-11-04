<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Schema::getIndexes('productos') as $index) {
            if ($index['unique'] && $index['columns'] === ['codigobar']) {
                Schema::table('productos', fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
        if (! Schema::hasIndex('productos', ['codigobar'])) {
            Schema::table('productos', fn (Blueprint $table) => $table->index('codigobar', 'productos_codigobar_index'));
        }
    }

    public function down(): void
    {
        if (DB::table('productos')->whereNotNull('codigobar')->groupBy('codigobar')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Existen códigos de barras compartidos; no se puede restaurar la restricción única.');
        }
        Schema::table('productos', fn (Blueprint $table) => $table->unique('codigobar', 'uq_productos_codigobar'));
    }
};
