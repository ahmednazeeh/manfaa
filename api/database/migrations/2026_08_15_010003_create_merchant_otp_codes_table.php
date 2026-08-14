<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phone-verification codes for MERCHANT self-signup (§1 decision
     * 2026-08-15). Deliberately a separate table from the customer
     * otp_codes rows — the two signup flows must never share or race over
     * each other's codes, and their retention/audit stories differ.
     * Mechanics mirror the customer table exactly: hashed code, 10-minute
     * expiry, 5 attempts, consumed_at supersession, and a sha256-hashed
     * short-lived signup token minted on successful verification.
     */
    public function up(): void
    {
        Schema::create('merchant_otp_codes', function (Blueprint $table) {
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
        Schema::dropIfExists('merchant_otp_codes');
    }
};
