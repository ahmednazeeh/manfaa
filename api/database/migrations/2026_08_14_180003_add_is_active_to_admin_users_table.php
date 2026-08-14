<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        // Deactivation is the only removal path (no DELETE), and the role
        // enum gets the same varchar+CHECK treatment as every other enum.
        DB::statement(
            "ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('admin', 'superadmin'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check');

        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
