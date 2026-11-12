<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_information_stream', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('sequence');
        });
        DB::table('pos_sale_information_stream')->insert(['id' => 1, 'sequence' => 0]);
        Schema::create('pos_sale_alerts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->integer('stock_id')->nullable();
            $table->unsignedBigInteger('producto_id')->nullable();
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->string('titulo', 150);
            $table->json('requisitos');
            $table->boolean('activo');
            $table->boolean('retirada')->default(false);
            $table->unsignedBigInteger('revision')->unique();
            $table->timestamps();
        });
        Schema::create('pos_branch_balances', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->integer('stock_id');
            $table->unsignedBigInteger('producto_id');
            $table->decimal('cantidad', 19, 4);
            $table->unsignedBigInteger('revision')->unique();
            $table->timestamp('updated_at');
            $table->primary(['stock_id', 'producto_id']);
        });
        Schema::create('pos_branch_balance_receipts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('request_id')->primary();
            $table->integer('stock_id');
            $table->char('hash', 64);
        });
    }

    public function down(): void
    {
        throw new LogicException('Conservar las alertas y las existencias publicadas por las sucursales.');
    }
};
