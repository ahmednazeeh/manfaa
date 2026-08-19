<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic payment verification, behind its own switch
 * (owner requirement 2026-08-19).
 *
 * Separate from `auto_transfer_enabled` on purpose: reading history and
 * moving money are different risks, and an operator may well want the
 * cheaper, read-only half on long before they trust the paying half. Both
 * default OFF, because the API access does not exist yet and a poller with
 * nothing to poll is noise in a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_settings', function (Blueprint $table): void {
            $table->boolean('auto_verify_enabled')->default(false);

            // Which upstream session to READ through, and which of our own
            // accounts to watch. Distinct from the paying profile: money
            // comes in to one account and goes out from another.
            $table->foreignId('verify_profile_id')->nullable()->constrained('transfer_profiles')->nullOnDelete();
            $table->string('verify_account')->nullable();

            // The owner's 15 minutes. Bounded on purpose — a poller with no
            // end runs forever against somebody else's API for orders that
            // were never paid, and the admin queue is the honest home for
            // anything it does not find.
            $table->unsignedSmallInteger('verify_window_minutes')->default(15);

            // How hard a name must match before the money is accepted
            // WITHOUT a person. Stored as a percentage so an operator can
            // tighten it from a screen after watching a few real matches.
            $table->unsignedSmallInteger('verify_min_score')->default(60);
        });
    }

    public function down(): void
    {
        Schema::table('transfer_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verify_profile_id');
            $table->dropColumn([
                'auto_verify_enabled',
                'verify_account',
                'verify_window_minutes',
                'verify_min_score',
            ]);
        });
    }
};
