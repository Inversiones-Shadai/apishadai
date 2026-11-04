<?php

use App\Services\Farma\TaskService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('farma_tarea_dias')->orderBy('id')->select('id', 'stock_id')->chunkById(100, function ($days): void {
            foreach ($days as $day) {
                DB::transaction(function () use ($day): void {
                    DB::table('stocks')->where('id', $day->stock_id)->lockForUpdate()->first();
                    $current = DB::table('farma_tarea_dias')->where('id', $day->id)->lockForUpdate()->first();
                    if ($current) {
                        app(TaskService::class)->publish($current);
                    }
                }, 3);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Conservar los metadatos del calendario publicados a HQ.');
    }
};
