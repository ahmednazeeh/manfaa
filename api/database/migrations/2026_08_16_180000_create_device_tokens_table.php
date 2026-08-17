<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            // Who the push belongs to — Customer or MerchantUser, the same
            // two audiences that may hold a mobile token.
            $table->morphs('tokenable');

            /*
             * THE BINDING THAT MATTERS.
             *
             * A push registration is tied to the auth token that created it,
             * and cascades when that token is deleted. Signing out, cutting
             * a device off from the website, hitting the token cap, and
             * deactivating a staff member all delete personal access tokens —
             * and every one of them must also stop the push, or a phone that
             * has been sold, lost or taken keeps announcing someone's
             * balance on its lock screen.
             *
             * Structural rather than a hook: there is no revocation path
             * anyone can add later that forgets to call this.
             */
            $table->foreignId('personal_access_token_id')
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();

            // The provider's registration token. Unique because one physical
            // device holds one — re-registering (a reinstall, a token
            // rotation, or a second person signing in on a shared phone)
            // must MOVE the row, never leave a stale twin that would go on
            // delivering to whoever held it last.
            $table->string('token', 512)->unique();

            $table->string('platform', 16);

            // What the device is running, so a push that a build cannot
            // handle can be withheld rather than crashing it.
            $table->unsignedInteger('app_build')->nullable();

            // Which language to send in. The templates already carry Thaana
            // twins; without this the push would have to guess.
            $table->string('locale', 5)->default('en');

            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index(['tokenable_type', 'tokenable_id', 'platform']);
        });

        DB::statement("ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_platform_check CHECK (platform IN ('ios', 'android'))");
        DB::statement("ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_locale_check CHECK (locale IN ('en', 'dv'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
