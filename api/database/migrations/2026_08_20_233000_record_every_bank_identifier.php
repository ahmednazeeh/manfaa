<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record EVERY identifier a matched bank credit carried, not just the one we
 * keyed on (owner, 2026-08-20: "record both incase available so none can be
 * double used").
 *
 * One BML transfer arrives under two names: the statement row is filed as
 * `FT26235BDLZB\B26` while the merchant's slip shows `BLAZ861828284421`. We
 * key dedup on the statement id because that one is guaranteed unique per
 * transaction, but keying on it ALONE means the slip reference is unguarded —
 * and a credit is only safely spent once if every name it answers to is spent
 * with it.
 *
 * `matched_trx_id` stays the race backstop: it is a single value, so a unique
 * index can enforce it, which a set cannot. This column is what the courtesy
 * check reads, and it spans BOTH tables — a credit spent on a settlement must
 * not also verify a customer order.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'settlement_payments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->jsonb('matched_trx_refs')->nullable();
            });

            // Containment lookups (`@> '["FT..."]'`) need GIN to stay cheap
            // once these tables are long.
            DB::statement(
                "create index {$table}_matched_trx_refs_gin on {$table} using gin (matched_trx_refs jsonb_path_ops)",
            );

            // Backfill: everything already matched answered to at least the
            // identifier we kept, so the set is never emptier than the column.
            DB::statement(
                "update {$table} set matched_trx_refs = jsonb_build_array(matched_trx_id) where matched_trx_id is not null",
            );
        }
    }

    public function down(): void
    {
        foreach (['orders', 'settlement_payments'] as $table) {
            DB::statement("drop index if exists {$table}_matched_trx_refs_gin");

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('matched_trx_refs');
            });
        }
    }
};
