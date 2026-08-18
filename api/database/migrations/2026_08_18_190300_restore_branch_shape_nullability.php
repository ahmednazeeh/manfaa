<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The previous migration tightened this constraint to require a branch on
 * every branch-shaped change. That was wrong, and the suite caught it:
 * deleting a branch NULLS `branch_id` on the history that referenced it
 * (`nullOnDelete`), and those rows must survive — the reviewer's record of a
 * removal is precisely the thing that outlives the branch.
 *
 * So the rule is only ever about which kinds may NOT carry a branch. Branch
 * kinds are left unconstrained, exactly as they were before products
 * existed; `product_update` simply joins the list that carries none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE merchant_change_requests DROP CONSTRAINT merchant_change_requests_branch_shape_check');
        DB::statement("
            ALTER TABLE merchant_change_requests ADD CONSTRAINT merchant_change_requests_branch_shape_check
            CHECK (
                (kind IN ('profile', 'branch_create', 'product_update') AND branch_id IS NULL)
                OR kind IN ('branch_update', 'branch_delete')
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE merchant_change_requests DROP CONSTRAINT merchant_change_requests_branch_shape_check');
        DB::statement("
            ALTER TABLE merchant_change_requests ADD CONSTRAINT merchant_change_requests_branch_shape_check
            CHECK (
                (kind IN ('branch_update', 'branch_delete') AND branch_id IS NOT NULL)
                OR (kind IN ('profile', 'branch_create', 'product_update') AND branch_id IS NULL)
            )
        ");
    }
};
