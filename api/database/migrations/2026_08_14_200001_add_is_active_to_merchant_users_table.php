<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deactivation for merchant panel accounts, mirroring
     * admin_users.is_active: there is no DELETE — a deactivated user's
     * audit trail (created_by on rates, actor ids on transaction events)
     * keeps resolving. Login refuses inactive users and a live session
     * dies on its next request (MerchantSettingsServiceProvider).
     */
    public function up(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
