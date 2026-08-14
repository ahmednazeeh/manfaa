<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phone-verification codes for customer signup (§10 apps/web, §12 Phase 3).
     * Codes are stored hashed, expire after 10 minutes, and allow 5 attempts.
     * A successful verification stamps consumed_at and mints a short-lived
     * signup token (stored as a sha256 hash) that the register step redeems.
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->string('code_hash');
            $table->timestampTz('expires_at');
            $table->integer('attempts')->default(0);
            $table->timestampTz('consumed_at')->nullable();
            $table->string('signup_token_hash', 64)->nullable()->unique();
            $table->timestampTz('signup_token_expires_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
