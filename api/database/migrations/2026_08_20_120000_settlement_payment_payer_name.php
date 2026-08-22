<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name the transfer was actually made under, as it appears on the
 * merchant's own receipt.
 *
 * Auto-matching previously compared the bank's payer against
 * `merchants.bank_account_name`, falling back to the store's trading name.
 * Settlement 5 is why that is not enough: Tea Plus settled MVR 59.50, the
 * credit arrived from a company called INTERBRIDGE, and with no reference
 * typed there was nothing tying the two together — so a payment that was
 * plainly correct sat waiting for a person.
 *
 * A merchant reading their own receipt knows that name. Asking for it turns
 * the common case (a company paying under a parent or sister entity) from an
 * unmatchable payment into an exact one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->string('payer_name', 160)->nullable()->after('bank_ref');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropColumn('payer_name');
        });
    }
};
