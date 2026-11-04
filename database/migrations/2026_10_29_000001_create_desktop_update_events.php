<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_update_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('authorization_id')->primary();
            $table->uuid('request_nonce')->unique();
            $table->uuid('activation_id')->index();
            $table->uuid('license_id')->nullable();
            $table->string('action', 32);
            $table->string('status', 32);
            $table->string('reason', 64);
            $table->string('product', 32);
            $table->string('architecture', 8);
            $table->string('current_version', 23);
            $table->unsignedSmallInteger('api_contract');
            $table->unsignedSmallInteger('schema_version');
            $table->uuid('release_id')->nullable()->index();
            $table->unsignedInteger('release_revision')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        throw new LogicException('El historial de autorizaciones se conserva mediante despliegues compatibles.');
    }
};
