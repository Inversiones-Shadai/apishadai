<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_license_generation_challenges', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('actor_id')->primary();
            $table->uuid('id')->unique();
            $table->char('payload_hash', 64);
            $table->char('session_hash', 64);
            $table->char('recipient_hash', 64);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_license_generation_challenges');
    }
};
