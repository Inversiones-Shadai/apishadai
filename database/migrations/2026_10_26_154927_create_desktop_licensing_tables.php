<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_licenses', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->string('label', 160);
            $table->string('product', 32);
            $table->char('key_hash', 64)->unique();
            $table->string('key_suffix', 8);
            $table->unsignedInteger('seats');
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('desktop_license_devices', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('license_id');
            $table->foreign('license_id')->references('id')->on('desktop_licenses');
            $table->string('device_name', 100);
            $table->char('device_hash', 64);
            $table->char('key_hash', 64);
            $table->text('public_key');
            $table->char('active_identity', 64)->nullable()->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_validated_at');
            $table->timestamp('reserved_until');
            $table->timestamps();
            $table->unique(['license_id', 'key_hash']);
        });
        Schema::create('desktop_license_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->uuid('license_id')->nullable()->index();
            $table->uuid('activation_id')->nullable();
            $table->string('action', 32);
            $table->string('result', 64);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('desktop_license_nonces', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('nonce')->primary();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_license_nonces');
        Schema::dropIfExists('desktop_license_events');
        Schema::dropIfExists('desktop_license_devices');
        Schema::dropIfExists('desktop_licenses');
    }
};
