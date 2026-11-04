<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_license_customers', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('name_key', 160)->unique();
            $table->timestamps();
        });
        $customerId = (string) Str::uuid();
        DB::table('desktop_license_customers')->insert([
            'id' => $customerId, 'name' => 'DNDEV', 'name_key' => 'dndev', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        Schema::table('desktop_licenses', function (Blueprint $table): void {
            $table->uuid('customer_id')->nullable()->index();
        });
        DB::table('desktop_licenses')->whereNull('customer_id')->update(['customer_id' => $customerId]);
        Schema::table('desktop_licenses', function (Blueprint $table): void {
            $table->uuid('customer_id')->nullable(false)->change();
            $table->foreign('customer_id')->references('id')->on('desktop_license_customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('desktop_licenses', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
        Schema::dropIfExists('desktop_license_customers');
    }
};
