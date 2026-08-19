<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bank reference may verify exactly ONE order, ever
 * (owner requirement 2026-08-19).
 *
 * The verifier already skips a reference another order has claimed, but a
 * query check is a race: two workers reading the same history at the same
 * instant both see it unclaimed and both verify. This index is the actual
 * guarantee — the second write loses at the database, which is the only
 * place a guarantee about uniqueness can live.
 *
 * Partial, because the overwhelming majority of orders are verified by a
 * person and carry no matched reference at all; NULLs must not collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX orders_matched_trx_id_unique
            ON orders (matched_trx_id)
            WHERE matched_trx_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_matched_trx_id_unique');
    }
};
