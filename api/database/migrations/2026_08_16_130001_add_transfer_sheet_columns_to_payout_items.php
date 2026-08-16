<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The key each item carries onto the transfer sheet and back again.
        // A sequence rather than the row id: the id does not exist until
        // after the insert, and reading max(id)+1 is a race. Sequence values
        // are not returned by a rolled-back transaction, so a withdrawn draft
        // leaves a gap — gaps are fine, reuse would not be.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS payout_items_idempotency_key_seq');

        Schema::table('payout_items', function (Blueprint $table) {
            // Not nullable: an item with no key cannot be matched when the
            // filled sheet comes back. There are no rows to backfill.
            $table->string('idempotency_key')->unique();
            // The rest of the snapshot, beside the bank details already taken
            // at build time: the sheet must say the same thing on a re-export
            // as it said first time, and a customer who renames afterwards
            // must not rewrite a transfer instruction already with the bank.
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
        });

        // Hand the sequence's lifetime to the column it numbers. A standalone
        // sequence is not a table, so `migrate:fresh` — which drops tables —
        // would leave it behind, and the next migration run would fail on a
        // sequence that already exists while a rebuilt test database kept
        // counting from wherever the last run stopped. OWNED BY makes it fall
        // with the table, which is what it was always logically part of.
        DB::statement('ALTER SEQUENCE payout_items_idempotency_key_seq OWNED BY payout_items.idempotency_key');
    }

    public function down(): void
    {
        Schema::table('payout_items', function (Blueprint $table) {
            $table->dropColumn(['idempotency_key', 'customer_name', 'customer_phone']);
        });

        DB::statement('DROP SEQUENCE payout_items_idempotency_key_seq');
    }
};
