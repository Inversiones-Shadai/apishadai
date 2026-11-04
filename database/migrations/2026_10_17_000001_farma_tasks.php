<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farma_tareas', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->integer('stock_id');
            $table->unsignedBigInteger('usuario_pos_id');
            $table->string('titulo', 200);
            $table->text('descripcion');
            $table->string('estado', 20)->default('PENDIENTE');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('creado_por');
            $table->unsignedBigInteger('modificado_por')->nullable();
            $table->timestamps();
            $table->index(['stock_id', 'usuario_pos_id', 'estado']);
        });
        Schema::create('farma_tarea_cambios', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->integer('stock_id');
            $table->unsignedBigInteger('tarea_id');
            $table->longText('datos');
            $table->timestamp('created_at');
            $table->index(['stock_id', 'id']);
        });
        Schema::create('farma_tarea_solicitudes', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('request_id')->primary();
            $table->char('huella', 64);
            $table->longText('respuesta');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new LogicException('Conservar tareas, cambios y solicitudes para su auditoría.');
    }
};
