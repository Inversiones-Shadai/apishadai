<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desktop_licenses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('term_months')->nullable();
            $table->timestamp('term_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('desktop_licenses', function (Blueprint $table): void {
            $table->dropColumn(['term_months', 'term_started_at']);
        });
    }
};
