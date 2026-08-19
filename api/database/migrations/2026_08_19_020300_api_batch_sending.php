<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sending a whole batch through the bank API
 * (owner requirement 2026-08-19: "initiate transfers via API through queue
 * worker" as a third road beside the filled sheet and per-row mark-paid).
 *
 * `api_sent_at` is deliberately NOT `exported_at`. Nothing was exported —
 * no file exists — and the batch page says "Exported ..." off that column.
 * Two roads to the bank deserve two timestamps, so the page can say which
 * one this batch actually took.
 *
 * The customer payout items gain the columns the merchant ones already have,
 * because an API answer carries facts a sheet never did: how many attempts,
 * which approvals-queue record parked it, what the bank's own error code was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->timestampTz('api_sent_at')->nullable();
        });

        Schema::table('merchant_payout_batches', function (Blueprint $table): void {
            $table->timestampTz('api_sent_at')->nullable();
        });

        Schema::table('payout_items', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0);
            // An approvals-queue record id. Never filed as bank_reference:
            // it is not a transaction reference and the money has not moved.
            $table->string('approval_id')->nullable();
            $table->string('error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', fn (Blueprint $t) => $t->dropColumn('api_sent_at'));
        Schema::table('merchant_payout_batches', fn (Blueprint $t) => $t->dropColumn('api_sent_at'));
        Schema::table('payout_items', fn (Blueprint $t) => $t->dropColumn(['attempts', 'approval_id', 'error_code']));
    }
};
