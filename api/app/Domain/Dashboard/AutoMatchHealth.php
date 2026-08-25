<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Money\Percent;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Transfers\BankWatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "IN CASE AUTO BANK MATCHING EVER GETS STUCK" — the owner's own reason for
 * the dashboard, answered as HEALTH rather than as a queue length.
 *
 * A pending transfer is not one thing. It can be a transfer the server is
 * actively polling the bank for (fine — leave it alone), or a transfer
 * NOBODY is looking at any more, which is a person's job that nothing on the
 * platform will do. A single "8 pending" tile hides the difference, and the
 * difference is the entire question. So each surface reports:
 *
 *   watching_now           an open poll window right now
 *   waiting_on_human       pending and NOT watched, split by WHY — the four
 *                          machine reasons, because they call for four
 *                          different actions: window_expired (match it by
 *                          hand), never_watched (it arrived while the switch
 *                          was down), no_verify_profile (a platform bank
 *                          account is not routed to a read profile — a
 *                          CONFIGURATION fault, and the loudest of the four),
 *                          auto_verify_off (the platform switch is down, so
 *                          every transfer is now manual work)
 *   expired_unmatched_24h  windows that lapsed in the last day — the shape of
 *                          a problem that started recently
 *   matched_in_period      auto vs manual over the window, so a FALLING auto
 *                          rate is visible before the queue grows — plus
 *                          differing_amounts, the matches where the bank
 *                          credited something other than the merchant typed
 *                          (owner, 2026-08-25). Not an error count: the
 *                          credited figure is the bank's and the money is
 *                          real. A rising one is worth a look at the slips.
 *
 * BOTH FLOWS, SEPARATELY LABELLED: settlement payments and wallet top-ups
 * are matched by two different verifiers against two different tables, and
 * one of them stalling while the other is healthy is precisely the fact this
 * panel has to be able to show.
 *
 * THE RULE IS NOT RESTATED HERE. Whether a row is watched is asked of
 * {@see BankWatch} — the same class, the same five gates in the same order,
 * that the merchant's own progress endpoints answer from. A second copy of
 * that logic would be a second answer to "is anyone looking at my transfer",
 * and the two screens would eventually disagree.
 *
 * What this class does own is the GROUPING. BankWatch answers about one row;
 * counting every pending row through it would be a query per row. So the
 * pending rows are grouped in SQL into (destination account × window state)
 * buckets — the only two facts the gates read off a row — and BankWatch is
 * asked ONCE per bucket, with a representative window. The answers are
 * memoised across both surfaces, because the rule does not care which table
 * the row came from. Live shape: one or two platform bank accounts, three
 * window states, so a handful of calls whatever the queue depth.
 */
final class AutoMatchHealth
{
    /** No window on the row at all — nothing was ever dispatched for it. */
    private const string BUCKET_NEVER = 'never';

    /** poll_until is in the future: a job is due to look again. */
    private const string BUCKET_OPEN = 'open';

    /** poll_until has passed: the window closed with nothing found. */
    private const string BUCKET_EXPIRED = 'expired';

    /** The reasons a PENDING row can be unwatched, in the order they are shown. */
    private const array REASONS = [
        BankWatch::REASON_WINDOW_EXPIRED,
        BankWatch::REASON_NEVER_WATCHED,
        BankWatch::REASON_NO_VERIFY_PROFILE,
        BankWatch::REASON_AUTO_VERIFY_OFF,
    ];

    /** @var array<string, array{bool, ?string}> bucket key => BankWatch's answer */
    private array $answers = [];

    public function __construct(private readonly BankWatch $watch) {}

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $now = CarbonImmutable::now('UTC');
        $this->answers = [];

        return [
            // The bank account a settlement was paid into lives on the
            // SETTLEMENT (the payment row carries no destination), which is
            // exactly where TransferProgress reads it from too.
            'settlement_payments' => $this->surface(
                DB::table('settlement_payments')
                    ->join('settlements', 'settlements.id', '=', 'settlement_payments.settlement_id')
                    ->where('settlement_payments.state', 'pending'),
                'settlements.platform_bank_account_id',
                'settlement_payments.poll_until',
                DB::table('settlement_payments'),
                $now,
                $period,
            ),
            'wallet_top_ups' => $this->surface(
                DB::table('wallet_top_ups')->where('wallet_top_ups.state', 'pending'),
                'wallet_top_ups.platform_bank_account_id',
                'wallet_top_ups.poll_until',
                DB::table('wallet_top_ups'),
                $now,
                $period,
            ),
        ];
    }

    /**
     * One flow's health: two queries — the pending rows bucketed, and the
     * period's matches split by who matched them.
     *
     * @param  Builder  $pending  the flow's pending rows, joined to whatever carries the destination
     * @param  string  $accountColumn  qualified column holding platform_bank_account_id
     * @param  string  $pollUntilColumn  qualified column holding the watch window
     * @param  Builder  $table  the flow's own table, for the matched split
     * @return array<string, mixed>
     */
    private function surface(
        Builder $pending,
        string $accountColumn,
        string $pollUntilColumn,
        Builder $table,
        CarbonImmutable $now,
        ReportPeriod $period,
    ): array {
        $buckets = DB::query()
            ->fromSub(
                $pending
                    ->selectRaw($accountColumn.' AS account_id')
                    ->selectRaw(
                        'CASE'
                        ." WHEN {$pollUntilColumn} IS NULL THEN '".self::BUCKET_NEVER."'"
                        ." WHEN {$pollUntilColumn} > ? THEN '".self::BUCKET_OPEN."'"
                        ." ELSE '".self::BUCKET_EXPIRED."'"
                        .' END AS bucket',
                        [$now],
                    )
                    // The window that closed in the last day, counted on the
                    // same pass: a lapse is a pending row whose watch ran out
                    // recently, whatever the gate above says about why.
                    ->selectRaw(
                        "CASE WHEN {$pollUntilColumn} >= ? AND {$pollUntilColumn} < ? THEN 1 ELSE 0 END AS lapsed_24h",
                        [$now->subDay(), $now],
                    ),
                'pending',
            )
            ->groupBy('account_id', 'bucket')
            ->selectRaw('account_id, bucket, COUNT(*) AS n, COALESCE(SUM(lapsed_24h), 0) AS lapsed_24h')
            ->get();

        $watching = 0;
        $lapsed24h = 0;
        $waiting = array_fill_keys(self::REASONS, 0);

        foreach ($buckets as $bucket) {
            $count = (int) $bucket->n;
            $lapsed24h += (int) $bucket->lapsed_24h;

            $accountId = $bucket->account_id === null ? null : (int) $bucket->account_id;
            [$isWatching, $reason] = $this->ask($accountId, (string) $bucket->bucket, $now);

            if ($isWatching) {
                $watching += $count;

                continue;
            }

            $reason ??= BankWatch::REASON_WINDOW_EXPIRED;
            $waiting[$reason] = ($waiting[$reason] ?? 0) + $count;
        }

        return [
            'pending_total' => $watching + array_sum($waiting),
            'watching_now' => $watching,
            'waiting_on_human' => ['total' => array_sum($waiting), ...$waiting],
            'expired_unmatched_24h' => $lapsed24h,
            'matched_in_period' => $this->matchedSplit($table, $period),
        ];
    }

    /**
     * BankWatch's answer for a whole bucket, asked once and remembered.
     *
     * The representative window is chosen to land the row on the same side
     * of gate 5 as every row in its bucket: absent, a day ahead, or a day
     * behind. Nothing else about a row reaches the rule.
     *
     * @return array{bool, ?string}
     */
    private function ask(?int $accountId, string $bucket, CarbonImmutable $now): array
    {
        $key = ($accountId ?? 'none').':'.$bucket;

        return $this->answers[$key] ??= $this->watch->on(
            'pending',
            match ($bucket) {
                self::BUCKET_NEVER => null,
                self::BUCKET_OPEN => $now->addDay(),
                default => $now->subDay(),
            },
            $accountId,
        );
    }

    /**
     * The period's matches, split by who found them. `auto_rate_percent`
     * follows PLAN §1's wire format — a 2-decimal percent string — and is
     * null when nothing matched at all, because a rate over no matches is
     * not 0%, it is nothing to report.
     *
     * `differing_amounts` counts the matches where the bank credited
     * something other than the merchant typed (owner, 2026-08-25). NOT an
     * error count: the money is real and the credited figure is the bank's,
     * so a discrepancy is worth an auditor's eye and nothing louder. It is a
     * third FILTER aggregate on the SAME row this method already reads — no
     * extra query reaches the landing page for it.
     *
     * The predicate is the SQL twin of the models' own `amountDiffers()`:
     * a NULL `received_laari` is not a discrepancy, it is an unknown, and
     * `<>` over NULL would drop the row anyway.
     *
     * @return array{total: int, auto: int, manual: int, differing_amounts: int, auto_rate_percent: string|null}
     */
    private function matchedSplit(Builder $table, ReportPeriod $period): array
    {
        $row = $table
            ->where('state', 'matched')
            ->where('matched_at', '>=', $period->start)
            ->where('matched_at', '<', $period->end)
            ->selectRaw('COUNT(*) AS n, COUNT(*) FILTER (WHERE auto_matched) AS auto_n')
            ->selectRaw('COUNT(*) FILTER (WHERE received_laari IS NOT NULL AND received_laari <> amount_laari) AS differing_n')
            ->first();

        $total = (int) $row->n;
        $auto = (int) $row->auto_n;

        return [
            'total' => $total,
            'auto' => $auto,
            'manual' => $total - $auto,
            'differing_amounts' => (int) $row->differing_n,
            // A rate that was OBSERVED, not chosen: Percent::effectiveRate is
            // the house's integer half-up derivation, and it answers null
            // over nothing rather than a "0.00" that would read as a stall.
            'auto_rate_percent' => Percent::effectiveRate($auto, $total),
        ];
    }
}
