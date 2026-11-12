<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setFamilyNullable(true);
    }

    public function down(): void
    {
        if (DB::table('productos')->whereNull('familia')->exists()) {
            throw new RuntimeException('Asigna una familia a los productos que no tienen una antes de revertir esta migración.');
        }

        $this->setFamilyNullable(false);
    }

    private function setFamilyNullable(bool $nullable): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $column = collect(Schema::getColumns('productos'))->firstWhere('name', 'familia');
            $type = $column['type'] ?? '';
            if (! preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?\z/i', $type)) {
                throw new LogicException('Tipo de columna productos.familia no reconocido.');
            }

            DB::statement('ALTER TABLE productos MODIFY familia '.$type.($nullable ? ' NULL' : ' NOT NULL'));

            return;
        }

        Schema::table('productos', fn (Blueprint $table) => $table->unsignedBigInteger('familia')->nullable($nullable)->change());
    }
};
