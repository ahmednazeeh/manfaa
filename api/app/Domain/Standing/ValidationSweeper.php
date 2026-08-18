<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\InvalidTransitionException;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Day 0 of the §7 clock: transactions still awaiting_validation whose
 * merchant's validation window has elapsed since occurred_at move to
 * payable_unfunded via TransitionService::makePayable, which stamps
 * clock_start_at and due_at (+15 days, evaluated in the business timezone).
 *
 * Idempotent by construction — a swept transaction is no longer
 * awaiting_validation, and a concurrent transition loses inside the
 * lock-then-revalidate in TransitionService and is simply skipped.
 */
final readonly class ValidationSweeper
{
    public function __construct(
        private TransitionService $transitions,
        private NotificationService $notifications,
    ) {}

    /**
     * @return int the number of transactions moved onto the settlement clock
     */
    public function run(): int
    {
        $now = CarbonImmutable::now('UTC');

        $ids = DB::table('transactions')
            ->join('merchants', 'merchants.id', '=', 'transactions.merchant_id')
            ->where('transactions.state', TransactionState::AwaitingValidation->value)
            ->whereRaw('transactions.occurred_at + make_interval(days => merchants.validation_window_days) <= ?', [$now])
            ->orderBy('transactions.id')
            ->pluck('transactions.id');

        $swept = 0;

        foreach ($ids as $id) {
            $transaction = Transaction::query()->find($id);

            if ($transaction === null) {
                continue;
            }

            try {
                if ($transaction->origin === 'marketplace') {
                    // Funded from the moment the customer paid us. It skips
                    // the receivable state entirely and becomes payable to
                    // the SHOPPER — which is what confirmation means here.
                    $this->transitions->confirm($transaction, Actor::system());
                } else {
                    $this->transitions->makePayable($transaction, Actor::system());
                }

                $swept++;

                // Tell the customer their Pending just became Confirmed —
                // the "when validated" moment (owner request 2026-08-17).
                // This is the WINDOW-CLOSED path only: a zero-window or
                // backdated credit confirms in the same breath it is earned,
                // and CashbackEarned already announced that. Zero-value and
                // customerless rows have nothing to announce.
                $customer = $transaction->customer;

                if ($customer !== null && (int) $transaction->cashback_laari > 0) {
                    $this->notifications->send(NotificationTemplateKey::CashbackConfirmed, $customer, [
                        'amount' => NotificationService::money((int) $transaction->cashback_laari),
                        'store' => (string) $transaction->merchant?->name,
                    ]);
                }
            } catch (InvalidTransitionException) {
                // A concurrent process moved it first — nothing left to do.
            }
        }

        return $swept;
    }
}
