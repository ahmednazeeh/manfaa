<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A phone-verification code (§12 Phase 3 customer signup). The code itself
 * is stored hashed; a consumed row may carry a short-lived signup token
 * (also hashed) minted by a successful verification.
 */
class OtpCode extends Model
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
