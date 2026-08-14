<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store self-signup lifecycle (§1 decision 2026-08-15): merchants gain
     * three pre-active statuses — draft (mid-wizard), pending_review
     * (submitted, awaiting the superadmin queue) and rejected (sent back
     * with a reason; the merchant edits and resubmits). None of the three
     * is EVER visible publicly; every public query filters status='active'.
     *
     *  - setup_state: jsonb map of completed wizard step keys, so quitting
     *    mid-wizard resumes on next login.
     *  - submitted_at / approved_at / rejected_at + approved_by +
     *    rejected_reason: the review trail.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE merchants DROP CONSTRAINT merchants_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE merchants ADD CONSTRAINT merchants_status_check
            CHECK (status IN ('draft', 'pending_review', 'rejected', 'active', 'suspended', 'closed'))
        SQL);

        Schema::table('merchants', function (Blueprint $table) {
            $table->jsonb('setup_state')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['setup_state', 'rejected_reason', 'submitted_at', 'approved_at', 'rejected_at']);
        });

        DB::statement('ALTER TABLE merchants DROP CONSTRAINT merchants_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE merchants ADD CONSTRAINT merchants_status_check
            CHECK (status IN ('active', 'suspended', 'closed'))
        SQL);
    }
};
