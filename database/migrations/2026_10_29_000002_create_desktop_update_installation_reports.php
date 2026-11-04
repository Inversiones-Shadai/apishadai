<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_update_installation_reports', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('authorization_id')->primary();
            $table->foreign('authorization_id')->references('authorization_id')->on('desktop_update_events');
            $table->uuid('activation_id')->index();
            $table->uuid('release_id');
            $table->unsignedInteger('release_revision');
            $table->string('product', 32);
            $table->string('result', 16);
            $table->string('reported_version', 23);
            $table->string('failure_code', 64);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        throw new LogicException('Los informes de instalación se conservan como auditoría.');
    }
};
