<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two follow-ups from the M2–M5 review.
     *
     * 1. `device_tokens.personal_access_token_id` carries a FOREIGN KEY with
     *    ON DELETE CASCADE but no index — `foreignId()->constrained()` emits
     *    the constraint only, and PostgreSQL does not index a referencing
     *    column for you. Every parent delete therefore sequential-scans the
     *    child table, and the daily `sanctum:prune-expired` sweep does one
     *    such scan per expired token. Both tables are empty today, so this
     *    is free now and would need CONCURRENTLY and a live window later.
     *
     * 2. `idempotency_keys` had no retention. A successful write's row is
     *    kept forever (only failures release the key), and M5 pointed every
     *    till sale at it — previously only a bounded set of POS vendors
     *    wrote there. `created_at` gets an index so the prune command can
     *    find old rows without scanning the table.
     */
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->index('personal_access_token_id');
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropIndex(['personal_access_token_id']);
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
