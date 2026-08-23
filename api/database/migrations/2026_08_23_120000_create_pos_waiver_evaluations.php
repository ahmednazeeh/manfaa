<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The POS-fee waiver programme (owner, 2026-08-23): a merchant that offers
 * at least 1% cashback all month and puts MVR 200,000 of earning sales —
 * or MVR 5,000 of cashback — through Manfaa gets that month's IsleBooks
 * invoice waived. One row per merchant per month, written by the monthly
 * evaluator; the platform (IsleBooks) reads verdicts, never raw data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_waiver_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // First day of the evaluated calendar month (business timezone).
            $table->date('month');
            // Only sales that EARNED count (owner): excluded-category lines
            // and zero-cashback sales contribute nothing to either figure.
            $table->bigInteger('volume_laari');
            $table->bigInteger('cashback_laari');
            // The lowest standing rate in force at any point of the month;
            // 0 when the merchant had no effective rate.
            $table->integer('min_rate_bp');
            // The overdue outstanding at evaluation time — unsettled
            // overdues disqualify (owner), whatever the volume.
            $table->bigInteger('overdue_laari');
            $table->string('merchant_status', 20);
            $table->boolean('qualified');
            $table->timestampTz('evaluated_at');
            $table->timestampsTz();
            $table->unique(['merchant_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_waiver_evaluations');
    }
};
