<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_matriz_inventario', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->integer('stock_id');
            $table->unsignedBigInteger('producto_id');
            $table->decimal('minimo', 19, 4);
            $table->decimal('maximo', 19, 4);
            $table->decimal('punto_reorden', 19, 4);
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('usuario_pos_id');
            $table->integer('device_id');
            $table->timestamp('updated_at');
            $table->unique(['stock_id', 'producto_id']);
        });
        Schema::create('stock_matriz_solicitudes', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('request_id')->primary();
            $table->integer('stock_id');
            $table->integer('device_id');
            $table->char('huella', 64);
            $table->longText('datos');
            $table->longText('respuesta');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new LogicException('Conservar matrices y solicitudes de inventario.');
    }
};
