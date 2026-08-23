<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-referral defence (owner, 2026-08-24 — NO TOLERANCE).
 *
 * `customer_devices` is the device-identity store: one row per (customer,
 * device), where the device is an HMAC-SHA256 of whatever sanctioned raw id
 * the surface could offer — Android SSAID, iOS identifierForVendor or the
 * Keychain UUID, or the web signup's long-lived `mfa_did` browser cookie.
 * The RAW id never touches the database; equality of hashes is all the
 * defence needs, and a leaked table names no device.
 *
 * `referral_disqualified_at` on the REFERRED customer is the permanent
 * stamp: referrer and referred were seen on the same device (or share a
 * live FCM token), so the bonus is never paid — no retry, no review queue.
 * It sits beside `referral_rewarded_at` so every award path can skip
 * disqualified customers with the same cheap NULL check it already makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('platform', 12);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            // One row per device per customer — record() upserts against
            // this; the hash index is what makes sharesDevice() an EXISTS.
            $table->unique(['customer_id', 'device_hash']);
            $table->index('device_hash');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->timestampTz('referral_disqualified_at')->nullable();
            $table->string('referral_disqualified_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['referral_disqualified_at', 'referral_disqualified_reason']);
        });

        Schema::dropIfExists('customer_devices');
    }
};
