<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantWallet extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'balance_laari' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    /** How long a refused claim stays on the wallet screen, with its reason. */
    public const int REJECTED_VISIBLE_DAYS = 7;

    /**
     * The merchant's top-up claims worth showing: those still waiting on
     * the bank or an admin, plus those refused in the last week — the
     * reason is what the merchant has to act on, and a claim that silently
     * vanished would leave them thinking the money is still on its way.
     * Keyed through merchant_id — a claim belongs to the merchant, and
     * exists before the wallet row has to.
     *
     * AND A CLAIM THE BANK ANSWERED WITH A DIFFERENT FIGURE (verifier round,
     * 2026-08-25). `received_laari` is stamped at MATCH, so a matched claim
     * left this relation the moment it became interesting: the wallet screen
     * could never render the discrepancy line it was given, and a merchant
     * who missed the push saw a movement for MVR 10.00 with no mention of the
     * MVR 20.00 they typed. Only the DIFFERING ones, and only for a week —
     * a matched claim that agrees with its credit is already the movement in
     * the ledger below, and listing it twice would say nothing.
     */
    public function recentTopUps(): HasMany
    {
        return $this->hasMany(WalletTopUp::class, 'merchant_id', 'merchant_id')
            ->where(function ($query): void {
                $query->where('state', 'pending')
                    ->orWhere(function ($rejected): void {
                        $rejected->where('state', 'rejected')
                            ->where('rejected_at', '>=', now()->subDays(self::REJECTED_VISIBLE_DAYS));
                    })
                    ->orWhere(function ($differing): void {
                        $differing->where('state', 'matched')
                            ->whereNotNull('received_laari')
                            ->whereColumn('received_laari', '<>', 'amount_laari')
                            ->where('matched_at', '>=', now()->subDays(self::REJECTED_VISIBLE_DAYS));
                    });
            });
    }
}
