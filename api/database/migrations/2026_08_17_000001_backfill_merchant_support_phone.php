<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data backfill for the support-phone materialisation (owner decision
 * 2026-08-17): rows written under the old NULL-means-same-as-contact
 * convention get the contact number copied in, so every store with a
 * contact number has a support number from here on. Merchant::booted()
 * keeps the invariant for all future writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('merchants')
            ->where(function ($q) {
                $q->whereNull('support_phone')->orWhere('support_phone', '');
            })
            ->whereNotNull('contact_phone')
            ->where('contact_phone', '!=', '')
            ->update(['support_phone' => DB::raw('contact_phone')]);
    }

    public function down(): void
    {
        // Irreversible by design: we cannot know which copies were backfilled
        // versus typed, and reverting to NULL would resurrect the bug.
    }
};
