<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
use App\Domain\Platform\BankAccountService;
use App\Domain\Platform\PlatformConfig;
use App\Models\MerchantWallet;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantWallet
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'balance_laari' => $this->balance_laari,
            'balance_mvr' => Laari::of($this->balance_laari)->formatMvr(),
            'currency' => $this->currency,
            // The smallest transfer the merchant may claim as a top-up —
            // read live so an admin raising it moves the form at once.
            'top_up_min_laari' => app(PlatformConfig::class)->walletTopUpMinLaari(),
            // Where a top-up may be sent: the platform's active accounts,
            // default first — the same list a settlement's
            // payment_instructions carries. Here because a store with
            // nothing payable and no settlement history (the one most
            // likely to pre-fund) has no other way to learn them.
            'bank_accounts' => app(BankAccountService::class)->activeAccounts(),
            // The wallet screen's toggle (owner, 2026-08-24): whether the
            // hourly run settles validated cashback from this balance. Read
            // here because this is where the merchant sees the money;
            // WRITTEN only through PATCH /merchant/preferences.
            'auto_settle_from_wallet' => (bool) $this->merchant->auto_settle_from_wallet,
            // Claims still waiting on the bank or an admin (owner,
            // 2026-08-24): money the merchant has sent that is not yet
            // balance, so the wallet screen can say so — plus claims
            // refused in the last week, with the reason, so the merchant
            // learns WHY rather than watching one vanish.
            'pending_top_ups' => $this->whenLoaded(
                'recentTopUps',
                fn () => $this->recentTopUps->map(fn (WalletTopUp $topUp): array => [
                    'id' => $topUp->id,
                    // The claim, then what the bank actually credited once
                    // it is known (owner, 2026-08-25). Both, because the
                    // wallet screen is where a merchant will notice that
                    // MVR 10.00 landed on a claim they typed MVR 20.00 on.
                    'amount_laari' => $topUp->amount_laari,
                    'amount_mvr' => Laari::of($topUp->amount_laari)->formatMvr(),
                    'received_laari' => $topUp->received_laari,
                    'received_mvr' => $topUp->received_laari === null
                        ? null
                        : Laari::of((int) $topUp->received_laari)->formatMvr(),
                    'amount_differs' => $topUp->amountDiffers(),
                    'bank_ref' => $topUp->bank_ref,
                    'bank' => $topUp->relationLoaded('platformBankAccount') && $topUp->platformBankAccount !== null
                        ? [
                            'id' => $topUp->platformBankAccount->id,
                            'bank_name' => $topUp->platformBankAccount->bank_name,
                            'account_no' => $topUp->platformBankAccount->account_no,
                            'account_name' => $topUp->platformBankAccount->account_name,
                        ]
                        : null,
                    'state' => $topUp->state,
                    'rejected_reason' => $topUp->rejected_reason,
                    'rejected_at' => $topUp->rejected_at?->toIso8601String(),
                    'created_at' => $topUp->created_at->toIso8601String(),
                ])->all(),
            ),
            'transactions' => $this->whenLoaded(
                'transactions',
                fn () => $this->transactions->map(fn (WalletTransaction $movement): array => [
                    'id' => $movement->id,
                    'amount_laari' => $movement->amount_laari,
                    'amount_mvr' => Laari::of($movement->amount_laari)->formatMvr(),
                    'balance_after_laari' => $movement->balance_after_laari,
                    'type' => $movement->type,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'description' => $movement->description,
                    'created_at' => $movement->created_at->toIso8601String(),
                ])->all(),
            ),
        ];
    }
}
