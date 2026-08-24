<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
use App\Models\WalletTopUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wallet top-up claim, as the merchant and the admin queue both read it.
 * The same shape as SettlementPaymentResource where the columns coincide,
 * so the two review screens can share their components.
 *
 * @mixin WalletTopUp
 */
class WalletTopUpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->name,
                'bank_account_name' => $this->merchant->bank_account_name,
            ]),
            'amount_laari' => $this->amount_laari,
            'amount_mvr' => Laari::of($this->amount_laari)->formatMvr(),
            'currency' => $this->currency,
            // What the MERCHANT told us — often nothing; the slip carries it.
            'bank_ref' => $this->bank_ref,
            'platform_bank_account_id' => $this->platform_bank_account_id,
            'platform_bank_account' => $this->whenLoaded('platformBankAccount', fn () => $this->platformBankAccount === null ? null : [
                'id' => $this->platformBankAccount->id,
                'bank_name' => $this->platformBankAccount->bank_name,
                'account_no' => $this->platformBankAccount->account_no,
                'account_name' => $this->platformBankAccount->account_name,
            ]),
            'state' => $this->state,
            // has_slip is what a UI branches on; slip_path is a private disk
            // path nothing can fetch — admins stream it through .../slip.
            'has_slip' => $this->slip_path !== null,
            'slip_mime' => $this->slip_mime,
            'slip_size_bytes' => $this->slip_size_bytes,
            'uploaded_by' => $this->uploaded_by,
            // What the BANK said, once matched — separate from bank_ref on
            // purpose: "the merchant claimed this" and "we found this" are
            // different facts.
            'auto_matched' => (bool) $this->auto_matched,
            'matched_trx_id' => $this->matched_trx_id,
            'matched_trx_refs' => $this->matched_trx_refs ?? [],
            'matched_payer_name' => $this->matched_payer_name,
            'matched_score' => $this->matched_score,
            'matched_by_rule' => $this->matched_by_rule,
            'matched_by' => $this->matched_by,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'wallet_transaction_id' => $this->wallet_transaction_id,
            // The bank watch as it actually stands on the row: until when
            // the poll runs (cleared on decision), and how often it has
            // looked. attempts = 0 with the window open means no job ever
            // ran — auto-verify was off at claim time.
            'poll_until' => $this->poll_until?->toIso8601String(),
            'poll_attempts' => (int) $this->poll_attempts,
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_reason' => $this->rejected_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
