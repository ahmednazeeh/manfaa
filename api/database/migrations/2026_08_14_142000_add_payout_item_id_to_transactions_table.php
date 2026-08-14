<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // A confirmed transaction joins at most one payout item; the link
            // is what prevents double inclusion across monthly batches. NULL
            // means "not yet paid out" — below-minimum sums simply stay
            // unlinked and carry forward (§13).
            $table->foreignId('payout_item_id')->nullable()->index()->constrained('payout_items');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_item_id');
        });
    }
};
