<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['compra_detalle', 'lotes'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('es_bonificacion')->default(false);
            });
        }
        Schema::table('compra_detalle', fn (Blueprint $table) => $table->index(
            ['producto', 'es_bonificacion', 'compra', 'id', 'costo'], 'compra_detalle_producto_bonificacion_costo_index',
        ));
        // Legacy purchase-wide scale flags are not evidence of a bonus line.
        // Retain those columns for compatibility; the return flow no longer uses them.
    }

    public function down(): void
    {
        Schema::table('compra_detalle', fn (Blueprint $table) => $table->dropIndex('compra_detalle_producto_bonificacion_costo_index'));
        foreach (['lotes', 'compra_detalle'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('es_bonificacion'));
        }
    }
};
