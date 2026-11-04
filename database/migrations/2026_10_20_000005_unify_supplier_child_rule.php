<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Children are the opened/fractionated presentation in this catalog.
        // Keep the configured child rule and mirror the legacy column.
        DB::table('proveedor_reglas_devolucion')->whereColumn('acepta_empezados', '<>', 'acepta_hijos')->update([
            'acepta_empezados' => DB::raw('acepta_hijos'),
            'revision' => DB::raw('revision + 1'),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve consistent policies instead of restoring contradictory values.
    }
};
