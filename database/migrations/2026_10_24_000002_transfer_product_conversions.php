<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencia_conversiones', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->unsignedBigInteger('usuario');
            $table->unsignedBigInteger('stock');
            $table->unsignedBigInteger('lote_padre');
            $table->unsignedBigInteger('producto_hijo');
            $table->unsignedInteger('cantidad');
            $table->json('respuesta');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('transferencia_conversiones')->exists()) {
            throw new RuntimeException('Conserva el historial de conversiones antes de retirar el esquema.');
        }
        Schema::dropIfExists('transferencia_conversiones');
    }
};
