<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_batches', function (Blueprint $table) {
            // Money waiting on bank details (§13): customers over the payout
            // minimum whose payout_bank/account/name were incomplete at build
            // time. Their transactions stay unlinked and carry forward; the
            // counters make the skipped money visible to admins.
            $table->integer('excluded_customer_count')->default(0);
            $table->bigInteger('excluded_total_laari')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', function (Blueprint $table) {
            $table->dropColumn(['excluded_customer_count', 'excluded_total_laari']);
        });
    }
};
