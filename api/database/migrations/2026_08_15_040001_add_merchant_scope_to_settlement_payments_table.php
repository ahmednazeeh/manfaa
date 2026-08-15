<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt-first idempotency. The existing unique (settlement_id,
     * bank_ref) stops one settlement booking the same transfer twice, but
     * under the receipt-first flow every submission creates a NEW settlement
     * — so a merchant double-tapping SUBMIT would produce two batches each
     * claiming the same bank transfer, and the per-settlement index would
     * never notice.
     *
     * The transfer is a MERCHANT-level fact, so the uniqueness is too:
     * merchant_id is denormalised onto the payment row (backfilled from the
     * settlement) and a PARTIAL unique index covers (merchant_id, bank_ref)
     * for every payment that is not rejected. Partial on purpose: a rejected
     * receipt describes a transfer the platform could not verify, and the
     * merchant must be able to submit that same reference again once the
     * problem is sorted — a permanently burnt reference would strand real
     * money.
     *
     * The old (settlement_id, bank_ref) index stays exactly as it was.
     */
    public function up(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->after('settlement_id');
        });

        DB::statement(<<<'SQL'
            UPDATE settlement_payments
            SET merchant_id = settlements.merchant_id
            FROM settlements
            WHERE settlements.id = settlement_payments.settlement_id
            SQL);

        // Every payment belongs to a settlement (FK), so the backfill leaves
        // nothing null and the column can carry the constraint from here on.
        DB::statement('ALTER TABLE settlement_payments ALTER COLUMN merchant_id SET NOT NULL');
        DB::statement('ALTER TABLE settlement_payments ADD CONSTRAINT settlement_payments_merchant_id_foreign FOREIGN KEY (merchant_id) REFERENCES merchants(id)');
        DB::statement('CREATE INDEX settlement_payments_merchant_id_index ON settlement_payments (merchant_id)');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settlement_payments_merchant_bank_ref_unique
            ON settlement_payments (merchant_id, bank_ref)
            WHERE bank_ref IS NOT NULL AND state <> 'rejected'
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS settlement_payments_merchant_bank_ref_unique');
        DB::statement('DROP INDEX IF EXISTS settlement_payments_merchant_id_index');
        DB::statement('ALTER TABLE settlement_payments DROP CONSTRAINT IF EXISTS settlement_payments_merchant_id_foreign');

        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropColumn('merchant_id');
        });
    }
};
