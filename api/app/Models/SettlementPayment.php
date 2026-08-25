<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TWO FIGURES, ONE TRANSFER (owner, 2026-08-25): `amount_laari` is the
 * merchant's CLAIM and never changes; `received_laari` is what the bank
 * actually credited, stamped at match time by the verifier. The funding
 * stack spends {@see creditedLaari()} — the bank's figure where we have
 * one — so a short payment flows down the existing partially_settled path
 * and an over-payment down the existing wallet-remainder path.
 */
class SettlementPayment extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settlement_id' => 'integer',
            'merchant_id' => 'integer',
            'amount_laari' => 'integer',
            'received_laari' => 'integer',
            'slip_size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'matched_by' => 'integer',
            'matched_at' => 'immutable_datetime',
            'rejected_by' => 'integer',
            'rejected_at' => 'immutable_datetime',
            'auto_matched' => 'boolean',
            // Every identifier the matched bank credit answered to.
            'matched_trx_refs' => 'array',
            'matched_score' => 'integer',
            'poll_started_at' => 'immutable_datetime',
            'poll_until' => 'immutable_datetime',
            'poll_attempts' => 'integer',
        ];
    }

    /**
     * THE FIGURE THAT FUNDS THE BATCH: what the bank credited, and the
     * merchant's claim only while no bank figure is known — a pending row,
     * or one an admin reconciled by hand off a statement without stating a
     * figure, which is exactly what those rows did before this existed.
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

    /** Snapshotted on the row, so it survives a settlement being rebuilt. */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
