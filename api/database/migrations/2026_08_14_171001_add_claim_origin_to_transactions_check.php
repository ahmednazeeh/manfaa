<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'claim' joins the origin list: an approved missing-transaction claim
     * creates a real cashback transaction — a missed real sale the merchant
     * funds — distinguishable forever from POS and manual entries.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_origin_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_origin_check CHECK (origin IN ('pos', 'manual', 'online_link', 'api_phone', 'card_linked', 'claim'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_origin_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_origin_check CHECK (origin IN ('pos', 'manual', 'online_link', 'api_phone', 'card_linked'))");
    }
};
