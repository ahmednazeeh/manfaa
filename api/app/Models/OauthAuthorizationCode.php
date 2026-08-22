<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-minute, single-use code handed over after a shopkeeper pressed
 * Authorise. It is the only part of the handshake that travels through a
 * browser redirect, which is exactly why it expires and the token does not.
 */
class OauthAuthorizationCode extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(PosVendor::class, 'pos_vendor_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
