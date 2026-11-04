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
        Schema::table('stock_matriz_inventario', function (Blueprint $table): void {
            $table->decimal('punto_reorden', 19, 4)->nullable()->change();
        });
        Schema::create('stock_matriz_existencias', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->integer('stock_id');
            $table->unsignedBigInteger('producto_id');
            $table->decimal('existencia_total', 19, 4);
            $table->unsignedBigInteger('revision');
            $table->integer('device_id');
            $table->timestamp('updated_at');
            $table->unique(['stock_id', 'producto_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Conservar las existencias y el diario de solicitudes.');
    }
};
