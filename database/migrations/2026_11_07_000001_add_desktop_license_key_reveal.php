<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desktop_licenses', function (Blueprint $table): void {
            $table->text('key_encrypted')->nullable();
        });
        Schema::create('desktop_license_key_challenges', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('license_id');
            $table->foreign('license_id')->references('id')->on('desktop_licenses')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->char('session_hash', 64);
            $table->char('recipient_hash', 64);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['license_id', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_license_key_challenges');
        Schema::table('desktop_licenses', function (Blueprint $table): void {
            $table->dropColumn('key_encrypted');
        });
    }
};
