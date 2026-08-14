<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-owned bank account merchants transfer settlements into. At most
 * one row is the active primary (partial unique index); that row is what the
 * merchant settlement payment instructions embed.
 */
#[Fillable(['bank_name', 'account_no', 'account_name', 'currency', 'is_primary', 'active'])]
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
        ];
    }
}
