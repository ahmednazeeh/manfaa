<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\InvalidTransitionException;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\SettlementState;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The +90 step of the §7 clock: payable_unfunded transactions whose due_at is
 * more than 90 days past move to written_off. Per transaction, one state
 * transition (reason merchant_default_90d) and one §8 write-off journal
 * posting the STORED row integers — never recomputed — are committed
 * atomically. The transition's lock-then-revalidate makes the pair
 * idempotent: an already-written-off row throws before anything posts.
 *
 * One write_off notice per merchant per run summarises what was written off.
 *
 * Transactions whose lines sit on a live (non-cancelled) settlement are
 * SKIPPED: their fate belongs to that batch — a late payment must still be
 * matchable (allocation transitions payable_unfunded → confirmed, and
 * written_off is terminal), and writing them off underneath the batch would
 * both strand the merchant's real money and double-clear the receivable.
 * Cancelling the stale batch releases them to the next sweep.
 */
final readonly class WriteOffService
{
    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
        private NoticeRecorder $notices,
        private PlatformConfig $config,
    ) {}

    /**
     * @return int the number of transactions written off
     */
    public function run(): int
    {
        // 90 days past due unless the admin-managed write_off_days setting
        // says otherwise.
        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subDays($this->config->writeOffDays());

        $ids = DB::table('transactions')
            ->where('state', TransactionState::PayableUnfunded->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $cutoff)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('settlement_lines')
                    ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
                    ->whereColumn('settlement_lines.transaction_id', 'transactions.id')
                    ->where('settlements.state', '!=', SettlementState::Cancelled->value);
            })
            ->orderBy('id')
            ->pluck('id');

        /** @var array<int, array{count: int, total_laari: int}> $byMerchant */
        $byMerchant = [];

        foreach ($ids as $id) {
            $transaction = Transaction::query()->find($id);

            if ($transaction === null) {
                continue;
            }

            try {
                DB::transaction(function () use ($transaction, $now): void {
                    $this->transitions->writeOff(
                        $transaction,
                        Actor::system(),
                        'merchant_default_90d',
                        ['due_at' => $transaction->due_at?->toIso8601String(), 'written_off_at' => $now->toIso8601String()],
                    );

                    // The stored line integers; a fully-zero row has nothing
                    // on the ledger to clear.
                    $accruedLaari = $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;

                    if ($accruedLaari > 0) {
                        $this->postings->writeOff(
                            $transaction->cashback_laari,
                            $transaction->fee_laari,
                            $transaction->fee_gst_laari,
                            referenceId: $transaction->id,
                        );
                    }
                });
            } catch (InvalidTransitionException) {
                continue; // A concurrent run already moved it.
            }

            $byMerchant[$transaction->merchant_id] ??= ['count' => 0, 'total_laari' => 0];
            $byMerchant[$transaction->merchant_id]['count']++;
            $byMerchant[$transaction->merchant_id]['total_laari'] +=
                $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;
        }

        foreach ($byMerchant as $merchantId => $summary) {
            $this->notices->record($merchantId, 'write_off', [
                'written_off_transactions' => $summary['count'],
                'written_off_laari' => $summary['total_laari'],
                'reason_code' => 'merchant_default_90d',
            ]);
        }

        return array_sum(array_column($byMerchant, 'count'));
    }
}
