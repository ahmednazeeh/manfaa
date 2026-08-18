<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin approval for store edits and new branches (MR9, design decided
 * 2026-08-18): what a shopper reads and trusts — the store's name, category,
 * channel, logo, website, its "what earns cashback" promise, and the branch
 * estate — stops going live silently and queues here for review.
 *
 * TWO json columns, and the second one is the point. `payload` holds the
 * PROPOSED values only; `snapshot` holds what those same fields read at
 * SUBMIT time. Without the snapshot the admin's before/after diff is
 * computed against the live row, which keeps moving — an instant contact
 * edit, a second (superseding) submission, an admin-panel correction — so a
 * reviewer looking at a two-day-old request would be shown a "before" that
 * nobody ever proposed changing away from. The snapshot freezes the half of
 * the diff that is otherwise not recoverable.
 *
 * `superseded` is a real terminal state rather than a delete: a merchant who
 * re-submits is never stuck behind their own earlier request, and the trail
 * of what they asked for first survives the correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_change_requests', function (Blueprint $table) {
            $table->id();
            // Cascade: a pending change to a deleted store is not a change.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 32);

            /**
             * The branch a branch_update / branch_delete targets — null for
             * `profile` and for `branch_create`, which has no branch yet.
             *
             * nullOnDelete, not cascade: APPROVING a branch_delete removes
             * the branch, and a cascade would delete the very row recording
             * that the deletion was approved. The `snapshot` keeps the
             * branch's id and values, so the history stays readable.
             */
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->nullOnDelete();

            $table->jsonb('payload');
            $table->jsonb('snapshot')->nullable();

            $table->string('status', 16)->default('pending');

            $table->foreignId('submitted_by')->constrained('merchant_users');
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->timestampsTz();

            // The merchant surfaces' read: "what of mine is waiting?"
            $table->index(['merchant_id', 'status']);
            // The admin queue's read: oldest pending first.
            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE merchant_change_requests
            ADD CONSTRAINT merchant_change_requests_kind_check
            CHECK (kind IN ('profile', 'branch_create', 'branch_update', 'branch_delete'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE merchant_change_requests
            ADD CONSTRAINT merchant_change_requests_status_check
            CHECK (status IN ('pending', 'approved', 'rejected', 'superseded'))
        SQL);

        // A `profile` or `branch_create` row pointing at a branch is a row no
        // reader could interpret. The branch kinds are NOT required to keep
        // theirs: approving a branch_delete nulls it by design (above), and
        // the snapshot carries the branch's identity from then on.
        DB::statement(<<<'SQL'
            ALTER TABLE merchant_change_requests
            ADD CONSTRAINT merchant_change_requests_branch_shape_check
            CHECK (
                (kind IN ('profile', 'branch_create') AND branch_id IS NULL)
                OR (kind IN ('branch_update', 'branch_delete'))
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_change_requests');
    }
};
