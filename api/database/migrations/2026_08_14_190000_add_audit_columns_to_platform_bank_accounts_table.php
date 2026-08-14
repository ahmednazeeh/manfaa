<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's settlement-receiving accounts are money-bearing
     * configuration (§13): every change must name the admin who made it.
     * Nullable — rows created before this migration have no recorded actor.
     */
    public function up(): void
    {
        Schema::table('platform_bank_accounts', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('admin_users');
            $table->foreignId('updated_by')->nullable()->constrained('admin_users');
        });
    }

    public function down(): void
    {
        Schema::table('platform_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
