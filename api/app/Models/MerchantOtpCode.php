<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A phone-verification code for merchant self-signup (§1 decision
 * 2026-08-15) — the merchant-scoped mirror of OtpCode. Same storage rules:
 * the code is bcrypt-hashed, a consumed row may carry a sha256-hashed
 * short-lived signup token that the register step redeems.
 */
class MerchantOtpCode extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'signup_token_expires_at' => 'immutable_datetime',
        ];
    }
}
