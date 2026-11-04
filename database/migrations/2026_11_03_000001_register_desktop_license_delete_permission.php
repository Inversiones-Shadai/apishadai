<?php

use Database\Seeders\DesktopLicensePermissionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new DesktopLicensePermissionSeeder)->run();
    }

    public function down(): void
    {
        // Preserve permission IDs and assignments.
    }
};
