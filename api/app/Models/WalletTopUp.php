<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's CLAIM that they transferred money into their Manfaa wallet —
 * the slip, the optional reference, the account they say they paid. Never
 * money on its own: only the match (automatic against the bank's history,
 * or an admin's click) credits the wallet, through WalletFunding::recordTopUp.
 *
 * The column set mirrors settlement_payments on purpose; see the migration.
 *
 * TWO FIGURES, ONE TRANSFER (owner, 2026-08-25): `amount_laari` is the
 * merchant's CLAIM and never changes; `received_laari` is what the bank
 * actually credited, stamped at match time. They usually agree. When they do
 * not, the bank wins — see {@see creditedLaari()}.
 */
class WalletTopUp extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'amount_laari' => 'integer',
            'received_laari' => 'integer',
            'platform_bank_account_id' => 'integer',
            'slip_size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'auto_matched' => 'boolean',
            'matched_trx_refs' => 'array',
            'matched_score' => 'integer',
            'matched_by' => 'integer',
            'matched_at' => 'immutable_datetime',
            'wallet_transaction_id' => 'integer',
            'poll_started_at' => 'immutable_datetime',
            'poll_until' => 'immutable_datetime',
            'poll_attempts' => 'integer',
            'rejected_by' => 'integer',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    /**
     * THE FIGURE THAT MOVES MONEY: what the bank credited, and the claim
     * only while no bank figure is known.
     *
     * The fallback is not a compromise — it is the state of every row before
     * a match, and of a settlement payment reconciled by hand where nobody
     * stated a figure. A top-up matched by an admin always carries one: the
     * admin queue requires it.
     */
    public function creditedLaari(): int
    {
        return (int) ($this->received_laari ?? $this->amount_laari);
    }

    /** True once we know the bank credited something else than was claimed. */
    public function amountDiffers(): bool
    {
        return $this->received_laari !== null
            && (int) $this->received_laari !== (int) $this->amount_laari;
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** The platform account the merchant said they paid into. */
    public function platformBankAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformBankAccount::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
