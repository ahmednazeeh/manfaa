<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * New stores start with a TWO day validation window instead of three.
     *
     * The window is the merchant's own refund grace period: cashback is not
     * payable until it closes, so a shorter one gets the customer their
     * money sooner while still covering same-day and next-day returns.
     *
     * Only the DEFAULT moves. Every existing merchant keeps the window they
     * are trading under — silently shortening a live store's refund grace
     * would start the §7 settlement clock a day early on sales already
     * recorded. The platform CEILING (platform setting
     * default_validation_window_days, which PreferencesController uses as
     * the maximum a merchant may choose) is also left alone, so a store
     * that wants three days can still set three.
     *
     * Raw ALTER rather than a Blueprint change(): doctrine/dbal is not
     * installed, and this touches only the column default — no type, no
     * nullability, nothing that could rewrite the table.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE merchants ALTER COLUMN validation_window_days SET DEFAULT 2');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE merchants ALTER COLUMN validation_window_days SET DEFAULT 3');
    }
};
