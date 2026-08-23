<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Ledger\Postings;
use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\Laari;
use App\Domain\Money\MerchantMoneyCache;
use App\Domain\Money\Rate;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Correcting a sale the till got wrong, while the refund window is still
 * open.
 *
 * A merchant could already CANCEL a sale (reversal), but not fix one: a
 * cashier who keyed 1,180.00 instead of 118.00 had to void and re-ring,
 * and the invoice number was then permanently spent, because
 * (merchant_id, invoice_no) is unique across every state. So the only
 * correction available was one that could not be carried out. This is that
 * correction.
 *
 * WHAT IT MAY TOUCH: the eligible amount, the reference-only sale total,
 * and the category split. Nothing else. In particular the RATE is not
 * re-resolved — it stays frozen at occurred_at exactly as §4 requires.
 * Amending is fixing what was rung up, not renegotiating the terms it was
 * rung up under, and a merchant who could move the rate by editing an
 * amount could quietly reprice yesterday's sales.
 *
 * WHEN: `awaiting_validation` only — the merchant's own refund window,
 * before the settlement clock starts. Once a sale is payable it is inside
 * the §7 clock and may already sit on a batch; once confirmed it is a
 * promise to the customer. Both are corrected by an admin adjustment, not
 * by editing the row. A BACKDATED credit is never amendable, for the same
 * reason it is never reversible (PLAN §1): the merchant credited a sale
 * whose window had already closed and was told it becomes final.
 *
 * LEDGER: the original accrual is reversed with the STORED integers and a
 * fresh accrual is posted at the new ones — never a delta. A delta would
 * be smaller to write and impossible to audit: the journal would show a
 * number that matches no document, where this shows the sale coming off at
 * exactly what was recorded and going back on at exactly what is now
 * recorded.
 *
 * The customer's balance follows automatically, because it is derived from
 * the transaction rows rather than stored.
 */
final readonly class AmendmentService
{
    public function __construct(
        private TermsResolver $terms,
        private CashbackCalculator $calculator,
        private LineSetParser $lineParser,
        private Postings $postings,
    ) {}

    /**
     * @param  list<array{category: string|null, amount_laari: int|string}>|null  $lines
     *                                                                                    null keeps a single-rate sale single-rate; supplying lines on a
     *                                                                                    sale that had none (or vice versa) is allowed — the shape of the
     *                                                                                    correction is the cashier's to decide.
     *
     * @throws NotAmendableException
     */
    public function amend(
        Transaction $transaction,
        Actor $actor,
        Laari $eligible,
        ?Laari $saleAmount,
        ?array $lines,
    ): Transaction {
        return DB::transaction(function () use ($transaction, $actor, $eligible, $saleAmount, $lines): Transaction {
            /** @var Transaction $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertAmendable($locked);

            MerchantMoneyCache::bump((int) $locked->merchant_id);

            $merchant = $locked->merchant;

            // Below the store's own minimum the sale earns nothing — the
            // same rule creation applies, re-applied to the new figure, so
            // a correction downwards can legitimately zero a sale that used
            // to earn.
            $belowMinimum = $eligible->value() < $merchant->min_eligible_laari;

            $parsedLines = $lines === null
                ? null
                : $this->lineParser->parse($merchant, $lines, $eligible);

            // The BASE terms frozen on the row are reused as the base for
            // the new figure; occurred_at is unchanged, so re-resolving
            // reproduces the decision creation made rather than today's.
            // A rate the store has since changed, or a promotion that has
            // since ended, cannot reach back into a sale already rung up.
            $baseBp = $locked->rate_bp;
            $feeBp = $locked->fee_bp;

            if ($parsedLines === null) {
                $priced = $this->calculator->calculate(
                    $eligible,
                    Rate::cashback($baseBp),
                    $feeBp,
                );
                $cashbackLaari = $belowMinimum ? 0 : $priced->cashbackLaari;
                $feeLaari = $belowMinimum ? 0 : $priced->feeLaari;
                $pricedLines = null;
            } else {
                $pricedLines = $this->terms->resolveLines(
                    $merchant->id,
                    $locked->branch_id,
                    $parsedLines,
                    $eligible,
                    $baseBp,
                    $locked->customer_id,
                    $locked->occurred_at->toImmutable(),
                    // A sale that now earns nothing prices without
                    // promotions, exactly as creation does for a zeroed row.
                    consultPromotions: ! $belowMinimum,
                );
                $cashbackLaari = $belowMinimum ? 0 : $pricedLines->cashbackTotal();
                $feeLaari = $belowMinimum ? 0 : $pricedLines->feeTotal();
            }

            $before = [
                'eligible_laari' => $locked->eligible_laari,
                'sale_laari' => $locked->sale_laari,
                'cashback_laari' => $locked->cashback_laari,
                'fee_laari' => $locked->fee_laari,
                'fee_gst_laari' => $locked->fee_gst_laari,
            ];

            // Off at exactly what was recorded...
            if ($locked->cashback_laari !== 0 || $locked->fee_laari !== 0 || $locked->fee_gst_laari !== 0) {
                $this->postings->reverseAccrual(
                    $locked->cashback_laari,
                    $locked->fee_laari,
                    $locked->fee_gst_laari,
                    'transaction',
                    $locked->id,
                );
            }

            $locked->forceFill([
                'eligible_laari' => $eligible->value(),
                'sale_laari' => $saleAmount?->value(),
                'cashback_laari' => $cashbackLaari,
                'fee_laari' => $feeLaari,
                // GST on the fee follows the same rule it did at creation.
                'fee_gst_laari' => 0,
                'reason_code' => $belowMinimum ? 'below_minimum' : null,
            ])->save();

            // The split is replaced wholesale rather than patched: a line
            // set is one snapshot of how a basket was priced, and half of an
            // old one beside half of a new one describes no basket at all.
            TransactionLine::query()->where('transaction_id', $locked->id)->delete();

            if ($pricedLines !== null) {
                foreach ($pricedLines->lines as $line) {
                    TransactionLine::query()->create([
                        'transaction_id' => $locked->id,
                        'product_category_id' => $line->categoryId,
                        'category_slug' => $line->slug,
                        'category_name_en' => $line->nameEn,
                        'amount_laari' => $line->amountLaari,
                        'currency' => $locked->currency,
                        'effective_rate_bp' => $line->rateBp,
                        'fee_bp' => $line->feeBp,
                        'cashback_laari' => $belowMinimum ? 0 : $line->cashbackLaari,
                        'fee_laari' => $belowMinimum ? 0 : $line->feeLaari,
                        'priced_by' => $line->pricedBy,
                        'sort' => $line->sort,
                    ]);
                }
            }

            // ...and back on at exactly what is now recorded.
            if ($cashbackLaari !== 0 || $feeLaari !== 0) {
                $this->postings->accrue(
                    $cashbackLaari,
                    $feeLaari,
                    0,
                    'transaction',
                    $locked->id,
                );
            }

            // The event log is the audit trail: what it was, what it is, and
            // who changed it. from_state equals to_state because an
            // amendment moves money, not state.
            $locked->events()->create([
                'from_state' => $locked->state->value,
                'to_state' => $locked->state->value,
                'actor_type' => $actor->actorType,
                'actor_id' => $actor->actorId,
                'reason_code' => 'amended',
                'meta' => [
                    'before' => $before,
                    'after' => [
                        'eligible_laari' => $locked->eligible_laari,
                        'sale_laari' => $locked->sale_laari,
                        'cashback_laari' => $locked->cashback_laari,
                        'fee_laari' => $locked->fee_laari,
                        'fee_gst_laari' => $locked->fee_gst_laari,
                    ],
                ],
                'created_at' => CarbonImmutable::now('UTC'),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @throws NotAmendableException
     */
    private function assertAmendable(Transaction $transaction): void
    {
        if ((bool) $transaction->backdated) {
            throw NotAmendableException::backdated();
        }

        if ($transaction->state !== TransactionState::AwaitingValidation) {
            throw NotAmendableException::state($transaction->state);
        }
    }
}
