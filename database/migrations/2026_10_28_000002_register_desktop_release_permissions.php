<?php

use Database\Seeders\DesktopReleasePermissionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new DesktopReleasePermissionSeeder)->run();
    }

    public function down(): void
    {
        // Preserve permission IDs and assignments. Never grant access automatically.
    }
};
