<?php

declare(strict_types=1);

namespace App\Domain\Claims;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\DuplicateInvoiceException;
use App\Domain\Cashback\MerchantNotActiveException;
use App\Domain\Cashback\NoEffectiveRateException;
use App\Domain\Cashback\TermsResolver;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Models\AdminUser;
use App\Models\Claim;
use App\Models\MerchantRate;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Approving a claim mints the cashback transaction the till never POSTed —
 * the ManualCreditService-equivalent path with origin 'claim'. It is a
 * missed REAL sale, so the merchant funds it: rate and fee are resolved
 * from the merchant's append-only rate history at the claimed purchase
 * date THROUGH the same TermsResolver as a live sale — a published
 * promotion covering the purchase date prices the claim exactly as the
 * till would have, cap accounting included (the claim carries no branch
 * context, so NULL-branch promotions resolve just as they do for a live
 * branchless credit). Money is the normal §4 ceiling computation, and the
 * accrual journal posts exactly as a live sale's would.
 *
 * Differences from the live path, all deliberate:
 * - No stale-timestamp hold: admin review IS the validation, so the row
 *   walks tracked → awaiting_validation → payable_unfunded immediately and
 *   the 15-day settlement clock starts at approval.
 * - Below-minimum refuses instead of recording a zeroed row — approving a
 *   claim that earns nothing is an admin mistake, not a till event.
 * - invoice_no is the claimed receipt number, so if the vendor ever
 *   retries the original sale the (merchant_id, invoice_no) unique
 *   constraint blocks the double credit.
 */
final readonly class ClaimApprovalService
{
    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
        private TermsResolver $terms,
    ) {}

    public function approve(Claim $claim, AdminUser $admin, ?string $resolutionNote = null): Transaction
    {
        try {
            return DB::transaction(function () use ($claim, $admin, $resolutionNote): Transaction {
                /** @var Claim $claim locked, re-read state */
                $claim = Claim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

                if (! in_array($claim->state, [ClaimState::Open->value, ClaimState::InReview->value], true)) {
                    throw ClaimAlreadyResolvedException::for($claim);
                }

                $merchant = $claim->merchant;

                if ($merchant->status !== 'active') {
                    throw MerchantNotActiveException::for($merchant);
                }

                if ($claim->claimed_amount_laari < $merchant->min_eligible_laari) {
                    throw ClaimBelowMinimumException::for($claim, $merchant->min_eligible_laari);
                }

                $now = CarbonImmutable::now('UTC');

                // The purchase instant: the claimed date at business-day
                // start, stored UTC. Rate resolution freezes against it —
                // the terms the sale actually met (§5), not today's rate.
                $occurredAt = CarbonImmutable::parse(
                    $claim->claimed_date->toDateString(),
                    (string) config('app.business_timezone', 'Indian/Maldives'),
                )->startOfDay()->utc();

                // The same terms a live credit resolves — standing history
                // plus any published promotion covering the purchase date,
                // caps and floor included. Runs inside this DB transaction,
                // as the cap advisory lock requires.
                [$result, $promotionId] = $this->terms->resolve(
                    $merchant->id,
                    null,
                    Laari::of($claim->claimed_amount_laari),
                    $this->resolveRateBp($merchant->id, $occurredAt, $claim),
                    $claim->customer_id,
                    $occurredAt,
                );

                $transaction = Transaction::query()->create([
                    'merchant_id' => $merchant->id,
                    'customer_id' => $claim->customer_id,
                    'promotion_id' => $promotionId,
                    'origin' => 'claim',
                    'invoice_no' => $claim->receipt_no !== null && $claim->receipt_no !== ''
                        ? $claim->receipt_no
                        : sprintf('CLAIM-%d', $claim->id),
                    'eligible_laari' => $claim->claimed_amount_laari,
                    'sale_laari' => null,
                    'currency' => $claim->currency,
                    'rate_bp' => $result->rateBp,
                    'fee_bp' => $result->feeBp,
                    'cashback_laari' => $result->cashbackLaari,
                    'fee_laari' => $result->feeLaari,
                    'fee_gst_laari' => 0,
                    'state' => TransactionState::Tracked,
                    'occurred_at' => $occurredAt,
                    'received_at' => $now,
                ]);

                $actor = Actor::admin($admin->id);

                $this->transitions->recordCreated($transaction, $actor, 'claim_approved', ['claim_id' => $claim->id]);

                if ($transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari > 0) {
                    $this->postings->accrue(
                        $transaction->cashback_laari,
                        $transaction->fee_laari,
                        $transaction->fee_gst_laari,
                        referenceId: $transaction->id,
                    );
                }

                // Admin review is the validation; the settlement clock (§7)
                // starts now, putting the reward on the merchant's next batch.
                $this->transitions->startValidation($transaction, $actor);
                $this->transitions->makePayable($transaction, $actor);

                $claim->update([
                    'state' => ClaimState::Approved->value,
                    'resolved_by' => $admin->id,
                    'resolved_at' => $now,
                    'resolution_note' => $resolutionNote,
                    'resulting_transaction_id' => $transaction->id,
                ]);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            throw DuplicateInvoiceException::for($claim->merchant, (string) $claim->receipt_no);
        }
    }

    /**
     * §5 sale-time resolution against the append-only rate history — the
     * same semantics as the live credit path.
     */
    private function resolveRateBp(int $merchantId, CarbonImmutable $occurredAt, Claim $claim): int
    {
        $rate = MerchantRate::query()
            ->where('merchant_id', $merchantId)
            ->where('effective_from', '<=', $occurredAt)
            ->where(function ($query) use ($occurredAt) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $occurredAt);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($rate === null) {
            throw NoEffectiveRateException::for($claim->merchant, $occurredAt);
        }

        return $rate->rate_bp;
    }
}
