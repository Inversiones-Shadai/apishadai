<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_matriz_existencias', function (Blueprint $table): void {
            $table->decimal('existencia_total', 19, 4)->nullable()->change();
            $table->boolean('reorden_activo')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Conservar la publicación y el historial de matrices.');
    }
};
