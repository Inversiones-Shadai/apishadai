<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_release_artifacts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->string('product', 32);
            $table->string('version', 23);
            $table->string('architecture', 8);
            $table->string('bucket', 63);
            $table->char('object_id', 64)->unique();
            $table->string('object_key', 512);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('package_name', 100);
            $table->string('publisher', 255);
            $table->json('metadata');
            $table->timestamp('verified_at');
            $table->timestamp('created_at');
            $table->index(['product', 'architecture']);
        });
        Schema::create('desktop_releases', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('artifact_id')->unique();
            $table->foreign('artifact_id')->references('id')->on('desktop_release_artifacts');
            $table->string('product', 32);
            $table->string('version', 23);
            $table->char('version_sort', 23);
            $table->string('architecture', 8);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->text('notes');
            $table->unsignedSmallInteger('min_api_contract');
            $table->unsignedSmallInteger('min_schema_version');
            $table->unsignedSmallInteger('max_schema_version')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['product', 'version', 'architecture'], 'desktop_release_product_version_arch_unique');
            $table->index(['product', 'architecture', 'status']);
        });
        Schema::create('desktop_release_tracks', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('product', 32);
            $table->string('architecture', 8);
            $table->uuid('pilot_release_id')->nullable();
            $table->uuid('stable_release_id')->nullable();
            $table->char('highest_pilot_version', 23)->nullable();
            $table->char('highest_stable_version', 23)->nullable();
            $table->primary(['product', 'architecture']);
            $table->foreign('pilot_release_id')->references('id')->on('desktop_releases');
            $table->foreign('stable_release_id')->references('id')->on('desktop_releases');
        });
        foreach (['shpos', 'shpos-administrator', 'hq-client'] as $product) {
            foreach (['x64', 'x86', 'arm64'] as $architecture) {
                DB::table('desktop_release_tracks')->insert(['product' => $product, 'architecture' => $architecture]);
            }
        }
        Schema::create('desktop_release_pilot_devices', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('release_id');
            $table->uuid('activation_id');
            $table->primary(['release_id', 'activation_id']);
            $table->foreign('release_id')->references('id')->on('desktop_releases');
            $table->foreign('activation_id')->references('id')->on('desktop_license_devices');
        });
        Schema::create('desktop_release_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->uuid('release_id')->index();
            $table->foreign('release_id')->references('id')->on('desktop_releases');
            $table->uuid('request_id')->unique();
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 32);
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        Schema::create('desktop_release_operations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('request_id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->char('request_hash', 64);
            $table->json('response')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Never drop a published catalog or its audit history as part of application rollback.
        throw new LogicException('El catálogo de versiones se conserva. Su retiro requiere un procedimiento explícito.');
    }
};
