<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three-tier merchant staff roles (PLAN §1 decision 2026-08-15): owner
 * (everything), MANAGER (rates, promotions, settlements, branches, product
 * categories — never bank account, staff management or API credentials),
 * staff (credit entry + reads).
 *
 * Widening only — every existing row is already 'owner' or 'staff', so no
 * backfill is needed and the constraint can be swapped in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE merchant_users DROP CONSTRAINT merchant_users_role_check');
        DB::statement("ALTER TABLE merchant_users ADD CONSTRAINT merchant_users_role_check CHECK (role IN ('owner', 'manager', 'staff'))");
    }

    public function down(): void
    {
        // Narrowing back would violate the constraint for anyone already
        // promoted, so managers fall back to the tier they were widened
        // from — staff — rather than blocking the rollback.
        DB::statement("UPDATE merchant_users SET role = 'staff' WHERE role = 'manager'");

        DB::statement('ALTER TABLE merchant_users DROP CONSTRAINT merchant_users_role_check');
        DB::statement("ALTER TABLE merchant_users ADD CONSTRAINT merchant_users_role_check CHECK (role IN ('owner', 'staff'))");
    }
};
