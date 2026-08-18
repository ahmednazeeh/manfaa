<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The MR9 shape constraint predates products and names every kind it knows,
 * so a `product_update` row satisfied neither arm and the insert failed at
 * the database — which is the constraint doing exactly its job.
 *
 * Rewritten to say the rule rather than enumerate the old cases: a change
 * carries a branch when, and only when, it is ABOUT a branch.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        DB::statement('ALTER TABLE merchant_change_requests DROP CONSTRAINT merchant_change_requests_branch_shape_check');
        DB::statement("
            ALTER TABLE merchant_change_requests ADD CONSTRAINT merchant_change_requests_branch_shape_check
            CHECK (
                ((kind IN ('profile', 'branch_create')) AND branch_id IS NULL)
                OR (kind IN ('branch_update', 'branch_delete'))
            )
        ");
    }
};
