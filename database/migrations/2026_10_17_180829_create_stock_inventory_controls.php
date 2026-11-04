<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_control_inventario', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('inventario_uuid')->primary();
            $table->integer('stock_id')->index();
            $table->integer('device_id');
            $table->unsignedBigInteger('usuario_pos_id');
            $table->string('nombre', 200);
            $table->longText('datos');
            $table->timestamp('created_at');
        });
        Schema::create('stock_control_inventario_solicitudes', function (Blueprint $table): void {
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
        throw new LogicException('Conservar los inventarios finalizados y sus solicitudes.');
    }
};
