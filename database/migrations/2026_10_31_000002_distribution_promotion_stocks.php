<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribucion', function (Blueprint $table): void {
            $table->boolean('promocion_todos_stocks')->default(true);
            $table->json('promocion_stock_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('distribucion', function (Blueprint $table): void {
            $table->dropColumn(['promocion_todos_stocks', 'promocion_stock_ids']);
        });
    }
};
