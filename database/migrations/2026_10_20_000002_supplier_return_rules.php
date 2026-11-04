<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            // Historical and external purchases remain unknown until confirmed.
            $table->boolean('en_escala')->nullable();
        });
        Schema::create('proveedor_reglas_devolucion', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('proveedor_id')->unique();
            $table->unsignedSmallInteger('meses_anticipacion');
            $table->boolean('acepta_hijos');
            $table->boolean('acepta_empezados');
            $table->boolean('acepta_escala');
            $table->string('tipo_devolucion', 20);
            $table->text('notas')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('actualizado_por');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_reglas_devolucion');
        Schema::table('compras', fn (Blueprint $table) => $table->dropColumn('en_escala'));
    }
};
