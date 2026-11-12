<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_lost_sales')) {
            Schema::create('pos_lost_sales', function (Blueprint $table): void {
                $table->uuid('request_id')->primary();
                $table->unsignedInteger('stock_id');
                $table->unsignedBigInteger('producto_id');
                $table->unsignedBigInteger('usuario_pos_id');
                $table->string('producto_codigo', 100);
                $table->string('producto', 255);
                $table->timestamp('reported_at');
                $table->index(['producto_id', 'reported_at']);
                $table->index(['stock_id', 'producto_id']);
                $table->index(['usuario_pos_id', 'producto_id']);
            });
        }

        DB::transaction(function (): void {
            $module = DB::table('modulos')->where('codigo', 'frm')->lockForUpdate()->value('id');
            $state = DB::table('vista_estados')->where('descripcion', 'ACTIVO')->value('id');
            if ($module === null || $state === null) {
                throw new LogicException('Falta el módulo Farma o el estado ACTIVO de vistas.');
            }

            $existing = DB::table('vistas')->where('codigo', 'vpd')->first();
            if ($existing && (string) $existing->modulo !== (string) $module) {
                throw new LogicException('El código vpd pertenece a otro módulo.');
            }
            $view = $existing?->id ?? DB::table('vistas')->insertGetId([
                'codigo' => 'vpd', 'nombre' => 'Ventas perdidas', 'modulo' => $module,
                'estado' => $state, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $action = DB::table('actionsvistas')->where('codigo', 'vpdbtn01')->first();
            if ($action && (string) $action->vista !== (string) $view) {
                throw new LogicException('La acción vpdbtn01 pertenece a otra vista.');
            }
            if (! $action) {
                DB::table('actionsvistas')->insert([
                    'codigo' => 'vpdbtn01', 'nombre' => 'Consultar ventas perdidas', 'vista' => $view,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }, 3);
    }

    public function down(): void
    {
        throw new LogicException('El historial de ventas perdidas debe conservarse.');
    }
};
