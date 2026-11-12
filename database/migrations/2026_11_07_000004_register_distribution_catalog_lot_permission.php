<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $view = DB::table('vistas as v')->join('modulos as m', 'm.id', '=', 'v.modulo')
                ->where('m.codigo', 'frm')->where('v.codigo', 'dst')->lockForUpdate()->value('v.id');
            if ($view === null) {
                throw new LogicException('Falta la vista Distribución en Farma.');
            }
            $existing = DB::table('actionsvistas')->where('codigo', 'dstbtn04')->first();
            if ($existing && (string) $existing->vista !== (string) $view) {
                throw new LogicException('La acción dstbtn04 pertenece a otra vista.');
            }
            if (! $existing) {
                DB::table('actionsvistas')->insert([
                    'vista' => $view, 'codigo' => 'dstbtn04', 'nombre' => 'Registrar lote de catálogo sin modificar existencias',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve the catalog action and permissions assigned after deployment.
    }
};
