<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribucion', function (Blueprint $table): void {
            $table->boolean('promocion_activa')->default(false);
            $table->decimal('promocion_porcentaje', 5, 2)->nullable();
            $table->date('promocion_hasta')->nullable();
            $table->boolean('mostrar_encabezado')->default(false);
            $table->string('encabezado_texto', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('distribucion', function (Blueprint $table): void {
            $table->dropColumn(['promocion_activa', 'promocion_porcentaje', 'promocion_hasta', 'mostrar_encabezado', 'encabezado_texto']);
        });
    }
};
