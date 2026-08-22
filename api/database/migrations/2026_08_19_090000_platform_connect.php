<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform connect — "IsleBooks would like to … Authorise / Deny"
 * (owner decision 2026-08-19).
 *
 * TWO TIERS, deliberately:
 *
 *  - A MERCHANT who wants their own integration keeps issuing themselves a
 *    key, as today. Nothing about that changes.
 *  - A PLATFORM that wants to connect to many merchants must be registered
 *    by a superadmin first. Only then can it run this handshake, and only
 *    for merchants who individually approve it.
 *
 * The gate matters more than it looks: without it, anyone could stand up a
 * consent screen naming themselves and ask any shopkeeper to press
 * Authorise. Registration is the point at which a human at Manfaa decides a
 * platform is real, what it may ever ask for, and where its callbacks live.
 *
 * NO TOKEN EXPIRY (owner decision). An accounting integration that dies
 * overnight and needs a shopkeeper to re-authorise is a worse outcome than
 * the risk expiry removes. Revocation therefore carries the whole weight,
 * so re-authorising REPLACES rather than accumulates, and the merchant can
 * see and cut every connection.
 *
 * The CODE expires in a minute and is single-use. It is the only part of
 * the handshake that travels through a browser redirect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_vendors', function (Blueprint $table): void {
            // The public half of the platform's identity — safe in a
            // redirect, meaningless without the secret.
            $table->string('client_id')->nullable()->unique();
            // Hashed: we only ever compare it, and a leaked registry must
            // not hand out working identities.
            $table->string('client_secret_hash')->nullable();

            /*
             * Registered callbacks, matched EXACTLY. No wildcards and no
             * prefixes — a loose redirect rule is how authorization codes
             * end up delivered to somebody else's server.
             */
            $table->json('redirect_uris')->nullable();

            /*
             * The ceiling on what this platform may ever ask a merchant
             * for. A consent screen can only request from this set, so a
             * platform approved for bookkeeping cannot later start asking
             * shopkeepers for customer names.
             */
            $table->json('allowed_abilities')->nullable();

            // What the shopkeeper is shown about who is asking.
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->string('website')->nullable();

            // Off by default: registering a platform and letting it loose
            // on merchants are two decisions, not one.
            $table->boolean('connect_enabled')->default(false);
            $table->foreignId('registered_by')->nullable()->constrained('admin_users');
        });

        Schema::create('oauth_authorization_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // A grant is a person's decision; the trail should name them.
            $table->foreignId('merchant_user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code_hash')->unique();
            $table->json('abilities');
            $table->string('redirect_uri');

            // PKCE, required rather than optional: without it, whoever
            // intercepts the code in a redirect can spend it.
            $table->string('code_challenge');
            $table->string('code_challenge_method', 10)->default('S256');

            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestamps();

            $table->index(['pos_vendor_id', 'merchant_id']);
        });

        DB::statement("
            ALTER TABLE oauth_authorization_codes
            ADD CONSTRAINT oauth_code_challenge_method_check
            CHECK (code_challenge_method IN ('S256'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_authorization_codes');

        Schema::table('pos_vendors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('registered_by');
            $table->dropColumn([
                'client_id', 'client_secret_hash', 'redirect_uris', 'allowed_abilities',
                'display_name', 'description', 'website', 'connect_enabled',
            ]);
        });
    }
};
