<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt-first settlements (PLAN §1 "Settlement flow"): a settlement now
     * only exists because a merchant already transferred and uploaded the
     * slip, so the payment row carries the receipt itself.
     *
     * slip_path already existed (admin-recorded fallback); what it lacked was
     * the provenance needed to review and to refuse a forgery: the stored
     * mime and byte size (both derived from MAGIC BYTES at upload, never from
     * the client's filename or Content-Type), and who uploaded it.
     *
     * The reject half of the review (Match | Reject) is recorded here too —
     * the reason is the merchant-visible explanation of why their settlement
     * was cancelled and their transactions released.
     */
    public function up(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->string('slip_mime')->nullable()->after('slip_path');
            $table->bigInteger('slip_size_bytes')->nullable()->after('slip_mime');
            $table->foreignId('uploaded_by')->nullable()->after('slip_size_bytes');
            $table->foreignId('rejected_by')->nullable()->after('matched_at');
            $table->timestampTz('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropColumn([
                'slip_mime',
                'slip_size_bytes',
                'uploaded_by',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
