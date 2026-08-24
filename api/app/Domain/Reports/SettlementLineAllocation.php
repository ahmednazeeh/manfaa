<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Settlement\PromptDiscount;
use App\Domain\Settlement\SettlementLines;
use App\Models\Settlement;
use App\Models\SettlementLine;
use Illuminate\Support\Facades\DB;

/**
 * What each transaction on a settlement actually COLLECTED, to the laari.
 *
 * The cashback report shows one row per transaction, and a finance person
 * reading it will add the Collected column up and compare it with the bank.
 * So the column has to sum to `settlements.amount_received_laari` exactly —
 * not approximately, and not "before rounding". Three things stand between
 * a line's gross due and the cash that arrived, and each is handled here:
 *
 * 1. THE PROMPT-PAYMENT DISCOUNT. It was priced on the BATCH — one ceiling
 *    over the summed fees (PLAN §1) — so spreading it per line re-rounds
 *    it, and Σ ceil(fee_i × rate) is always ≥ ceil(Σ fee_i × rate). The
 *    lines are therefore given their own ceiling in allocation order while
 *    the batch's POSTED relief lasts, and the LAST line takes whatever is
 *    left — which is where the re-rounding remainder lands. PromptDiscount's
 *    own statics do the arithmetic, so a line is discounted by exactly the
 *    rule the batch was priced under, at the rate STAMPED ON THE ROW: live
 *    is 10% today, the eight settlements from before the change are 5%, and
 *    a report that read the current setting would restate history. Posted,
 *    not promised: a partially settled batch has the relief withheld until
 *    the allocation that finishes it, and spreadDiscount() says why.
 *
 * 2. THE FORGIVEN SHORTFALL (§7, under MVR 1). It is not a property of any
 *    line — the platform absorbed a gap at the end of a batch — so it is
 *    read from the LEDGER (the settlement's own forgiveness journals) and
 *    shown against the last allocated line, the line the gap actually fell
 *    short on.
 *
 * 3. EVERYTHING ELSE THE CASH DID. A partial batch's unallocated remainder,
 *    an overpayment parked in the wallet, an adjustment credit that funded
 *    lines without cash: all of it is the difference between the lines and
 *    the money, and all of it lands on the last allocated line too. That is
 *    the only way the column can be trusted to add up, and the settlements
 *    sheet carries the batch-level figures beside it so nothing is hidden.
 *
 * A line with no `allocated_at` collected nothing yet and is deliberately
 * BLANK rather than zero — "no money has come for this line" and "the money
 * that came was zero" are different sentences. A settlement with no
 * allocated line at all therefore attributes nothing; its cash (if any) is
 * still on the settlements sheet as amount received.
 */
final readonly class SettlementLineAllocation
{
    /**
     * @param  array<int, int>  $collected  transaction id => laari collected
     * @param  array<int, int>  $discount  transaction id => laari of prompt discount
     * @param  array<int, int>  $forgiveness  transaction id => laari forgiven
     */
    private function __construct(
        public int $settlementId,
        public int $amountReceivedLaari,
        private array $collected,
        private array $discount,
        private array $forgiveness,
    ) {}

    /**
     * Reads the settlement's lines and its forgiveness journals itself. The
     * report path uses forLines() instead, having already loaded both.
     */
    public static function for(Settlement $settlement): self
    {
        return self::forLines(
            $settlement,
            SettlementLines::inAllocationOrder($settlement)->all(),
            self::forgivenLaari($settlement->id),
        );
    }

    /**
     * @param  list<SettlementLine>  $lines  in allocation order (oldest due_at first)
     * @param  int  $forgivenLaari  the §7 shortfall the platform absorbed on this batch
     */
    public static function forLines(Settlement $settlement, array $lines, int $forgivenLaari): self
    {
        $discount = self::spreadDiscount($settlement, $lines);

        $collected = [];
        $forgiveness = [];

        $allocated = array_values(array_filter(
            $lines,
            static fn (SettlementLine $line): bool => $line->allocated_at !== null,
        ));

        $remaining = (int) $settlement->amount_received_laari;
        $last = count($allocated) - 1;

        foreach ($allocated as $index => $line) {
            $transactionId = (int) $line->transaction_id;
            $net = SettlementLines::due($line) - ($discount[$transactionId] ?? 0);

            // The last allocated line absorbs the batch's whole residue —
            // forgiveness, an unallocated remainder, an overpayment — which
            // is what makes the column sum to the cash received.
            $take = $index === $last ? $remaining : max(0, min($net, $remaining));

            $collected[$transactionId] = $take;
            $remaining -= $take;
        }

        if ($last >= 0 && $forgivenLaari > 0) {
            $forgiveness[(int) $allocated[$last]->transaction_id] = $forgivenLaari;
        }

        return new self(
            settlementId: (int) $settlement->id,
            amountReceivedLaari: (int) $settlement->amount_received_laari,
            collected: $collected,
            discount: $discount,
            forgiveness: $forgiveness,
        );
    }

    /**
     * The relief the LEDGER actually granted, spread over the lines in
     * allocation order at the rate the batch was STAMPED with, remainder on
     * the last line.
     *
     * `discount_posted_laari`, not `discount_laari`. The two differ by
     * design: the discount is priced at submit and stored on
     * `discount_laari`, but SettlementAllocator::matchPayment withholds it
     * from any match that does not FINISH the batch ("granted for clearing
     * the board"), posting it only with the completing allocation and
     * recording what reached the ledger on `discount_posted_laari`. Spread
     * the promised figure and a partially-settled batch reports relief the
     * merchant never received — and, because line 101 nets it off what is
     * still owed, pushes the last allocated line's Collected above its own
     * Gross due. The same month's ledger-derived earnings report would say
     * the discount was zero, and the two workbooks would contradict each
     * other over one fact.
     *
     * Clamped to `discount_laari` so a row that somehow over-recorded a
     * posting can still never discount more than the batch was priced at.
     * On a settled batch the two are equal and nothing here changes.
     *
     * The Settlements sheet keeps showing `discount_laari` — the relief the
     * batch was quoted, and the figure `amount_due_laari` was computed from.
     * Promised and granted are different questions, and the two sheets
     * answer them separately rather than pretending they are one.
     *
     * @param  list<SettlementLine>  $lines
     * @return array<int, int> transaction id => laari
     */
    private static function spreadDiscount(Settlement $settlement, array $lines): array
    {
        $spread = [];
        $remaining = min((int) $settlement->discount_posted_laari, (int) $settlement->discount_laari);
        $rateBp = (int) ($settlement->discount_rate_bp ?? 0);
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $transactionId = (int) $line->transaction_id;

            if ($remaining <= 0) {
                $spread[$transactionId] = 0;

                continue;
            }

            if ($index === $last) {
                // Whatever the batch granted and the earlier lines did not
                // take. Never more than the relief itself, because the
                // per-line ceilings always sum to at least the batch's.
                $spread[$transactionId] = $remaining;
                $remaining = 0;

                continue;
            }

            $fee = PromptDiscount::ceilingBp((int) $line->fee_laari, $rateBp);
            $relief = $fee + PromptDiscount::gstRelief((int) $line->fee_gst_laari, $fee, (int) $line->fee_laari);

            $take = max(0, min($relief, $remaining));
            $spread[$transactionId] = $take;
            $remaining -= $take;
        }

        return $spread;
    }

    /**
     * The §7 shortfall the platform absorbed on one settlement, straight
     * from the ledger — never re-derived from amount_due minus received,
     * which would also count an adjustment credit or a discount as
     * forgiveness.
     */
    public static function forgivenLaari(int $settlementId): int
    {
        return self::forgivenBySettlement([$settlementId])[$settlementId] ?? 0;
    }

    /**
     * @param  list<int>  $settlementIds
     * @return array<int, int> settlement id => laari forgiven
     */
    public static function forgivenBySettlement(array $settlementIds): array
    {
        if ($settlementIds === []) {
            return [];
        }

        $rows = DB::table('ledger_entries')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
            ->where('ledger_journals.reference_type', 'settlement')
            ->whereIn('ledger_journals.reference_id', $settlementIds)
            ->where('ledger_journals.description', JournalKind::ShortfallForgiven->value)
            ->groupBy('ledger_journals.reference_id')
            ->selectRaw('ledger_journals.reference_id, SUM(ledger_entries.debit_laari) AS laari')
            ->get();

        $forgiven = [];

        foreach ($rows as $row) {
            $forgiven[(int) $row->reference_id] = (int) $row->laari;
        }

        return $forgiven;
    }

    /** Laari collected against one transaction, or null when nothing has been. */
    public function collectedFor(int $transactionId): ?int
    {
        return $this->collected[$transactionId] ?? null;
    }

    public function discountFor(int $transactionId): int
    {
        return $this->discount[$transactionId] ?? 0;
    }

    public function forgivenFor(int $transactionId): int
    {
        return $this->forgiveness[$transactionId] ?? 0;
    }

    /**
     * The invariant this class exists for: with at least one allocated line,
     * this equals the settlement's amount_received_laari exactly.
     */
    public function totalCollected(): int
    {
        return array_sum($this->collected);
    }

    public function totalDiscount(): int
    {
        return array_sum($this->discount);
    }

    public function hasAllocation(): bool
    {
        return $this->collected !== [];
    }
}
