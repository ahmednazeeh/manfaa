<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the receipt actually says, read by OCR.
 *
 * Replaces the `payer_name` column added earlier the same day, which asked
 * the MERCHANT to type the name their transfer went out under. That was the
 * wrong shape: the name is already on the slip they just uploaded, and a
 * field nobody fills is a field that does not work.
 *
 * Text rather than an extracted name on purpose. Receipts have no common
 * layout — BML, MIB and every banking app arrange sender, beneficiary and
 * reference differently — so parsing "the payer" out of one is guesswork.
 * Asking the opposite question is not: does the name the BANK gives for this
 * credit appear anywhere on the receipt the merchant uploaded? That is a
 * containment test against text we already hold, and it is what a person
 * does when they check a slip by eye.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropColumn('payer_name');
            $table->text('receipt_text')->nullable()->after('slip_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropColumn('receipt_text');
            $table->string('payer_name', 160)->nullable()->after('bank_ref');
        });
    }
};
