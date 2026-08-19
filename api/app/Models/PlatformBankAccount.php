<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform-owned bank account merchants transfer settlements into. At most
 * one row is the active primary (partial unique index); that row is what the
 * merchant settlement payment instructions embed.
 */
#[Fillable(['bank_name', 'account_no', 'account_name', 'currency', 'is_primary', 'active', 'verify_profile_id'])]
class PlatformBankAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'active' => 'boolean',
            'verify_profile_id' => 'integer',
        ];
    }

    /**
     * How this account's own history is read, for auto-verifying customer
     * payments into it. Null means nobody watches it and a person verifies
     * by hand — which is the ordinary state, not a fault.
     */
    public function verifyProfile(): BelongsTo
    {
        return $this->belongsTo(TransferProfile::class, 'verify_profile_id');
    }
}
