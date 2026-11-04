<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('farma_tareas', function (Blueprint $table): void {
            $table->string('programacion', 12)->default('UNICA');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('fechas')->nullable();
        });
        Schema::create('farma_tarea_dias', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('tarea_id');
            $table->date('fecha')->nullable();
            $table->integer('stock_id');
            $table->unsignedBigInteger('usuario_pos_id');
            $table->string('titulo', 200);
            $table->text('descripcion');
            $table->string('estado', 20);
            $table->unsignedBigInteger('revision');
            $table->boolean('activo');
            $table->timestamp('updated_at');
            $table->unique(['tarea_id', 'fecha']);
            $table->index(['stock_id', 'fecha']);
        });
        // Preserve the identity and state of requests already queued by older POS versions.
        DB::table('farma_tareas')->orderBy('id')->chunkById(100, function ($tasks): void {
            foreach ($tasks as $task) {
                DB::table('farma_tarea_dias')->insert([
                    'id' => $task->id, 'tarea_id' => $task->id, 'fecha' => null,
                    'stock_id' => $task->stock_id, 'usuario_pos_id' => $task->usuario_pos_id,
                    'titulo' => $task->titulo, 'descripcion' => $task->descripcion, 'estado' => $task->estado,
                    'revision' => $task->revision, 'activo' => $task->estado !== 'CANCELADA', 'updated_at' => $task->updated_at,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Conservar la programación y el historial diario de tareas.');
    }
};
