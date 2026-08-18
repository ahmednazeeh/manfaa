<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerWalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real, stored customer balance (owner decision 2026-08-19).
 *
 * Distinct from the DERIVED cashback figure BalanceQuery computes: that one
 * is a view over transactions, this one is an account with a ledger. Refunds
 * land here instantly; the balance is always withdrawable to a bank.
 */
class CustomerWallet extends Model
{
    /** @use HasFactory<CustomerWalletFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance_laari' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CustomerWalletEntry::class, 'wallet_id');
    }
}
