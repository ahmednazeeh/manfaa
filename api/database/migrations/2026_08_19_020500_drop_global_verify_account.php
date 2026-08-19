<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single global "account we watch" is gone, replaced by the per-account
 * mapping added a migration ago.
 *
 * It was wrong from the moment it was written: customers choose BML or MIB
 * at checkout, so ONE watched account can only ever verify half the orders
 * while appearing to watch all of them. A setting that silently covers half
 * the traffic is worse than no setting, so it is removed rather than left
 * as a fallback.
 *
 * Dropped in the same round it shipped, with the flag still off and no
 * production data behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verify_profile_id');
            $table->dropColumn('verify_account');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_settings', function (Blueprint $table): void {
            $table->foreignId('verify_profile_id')->nullable()->constrained('transfer_profiles')->nullOnDelete();
            $table->string('verify_account')->nullable();
        });
    }
};
