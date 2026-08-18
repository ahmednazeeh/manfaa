<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service unpublish (owner decision 2026-08-18): a store may take
 * itself off the app for a while — a renovation, a closed season, a supply
 * problem — and put itself back, with no admin in the loop either way.
 *
 * This is deliberately NOT a new `status`. Status is the ACCOUNT lifecycle
 * (draft → pending_review → active → suspended → closed) and 140 call sites
 * read it; a seventh value would have to be taught to every one of them on a
 * live platform, and would wrongly imply the account itself had changed.
 * Publication is orthogonal: an unpublished store is still active — it
 * settles, it reads every screen, its history stands, MR9 change-request
 * gating still applies to it — it simply is not offering cashback today.
 * So: a nullable timestamp, and the public queries gain one more condition.
 *
 * The two `*_notified_at` stamps are the owner's rate limit, held in the
 * database rather than the cache on purpose. A cache flush would re-open the
 * gate and let a merchant toggling a switch send a second blast to every
 * customer who ever shopped there; these survive a flush, a deploy, and a
 * queue restart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->timestampTz('unpublished_at')->nullable();
            $table->timestampTz('unpublish_notified_at')->nullable();
            $table->timestampTz('republish_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropColumn(['unpublished_at', 'unpublish_notified_at', 'republish_notified_at']);
        });
    }
};
