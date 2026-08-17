<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Adjustment\ReversalOutcome;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\AdminUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The admin hold-review queue (PLAN §13b task #22).
 *
 * on_hold means fraud or dispute review and nothing else since task #23 —
 * backdated credits go straight to payable_unfunded, so no automated path
 * parks a row here any more. What the queue holds is therefore a human
 * decision: rows a human held, plus the legacy `stale_timestamp` holds still
 * sitting in production data from the staleness rule that was removed.
 *
 * Two outcomes, both irreversible in the ordinary sense and both routed
 * through the same machinery every other state change uses:
 *
 *  - RELEASE — the review cleared. The target is DERIVED, never chosen by
 *    the caller: if the merchant's validation window has already elapsed the
 *    row is payable and the §7 clock is stamped in the SAME call; otherwise
 *    it goes back to whatever state it was held from, read out of the
 *    append-only event history.
 *  - REJECT — the review failed. The sale reverses through the §9.2 tree,
 *    mirroring the accrual from the STORED integers.
 *
 * The release clock is the defect PLAN §13b names. An earlier hold released
 * by hand landed in payable_unfunded with clock_start_at and due_at still
 * null: invisible to the escalation ladder, to the day-16 suspension and to
 * the 90-day write-off, so the merchant owed money on a clock that was never
 * running and the platform's only credit control silently skipped the row.
 * Nothing here writes the state and the clock as two steps — both go through
 * TransitionService::makePayable, one DB transaction, one event.
 */
final readonly class HoldReviewService
{
    /** Row + event qualifier written when a human clears a review. */
    public const string RELEASE_REASON = 'admin_release';

    /** Row + event qualifier written when a human refuses the sale. */
    public const string REJECT_REASON = 'admin_reject';

    public function __construct(
        private TransitionService $transitions,
        private ReversalService $reversals,
        private NotificationService $notifications,
    ) {}

    /**
     * The queue, oldest hold first — a review that has waited longest is the
     * one most in need of a decision.
     *
     * Each row carries what a reviewer needs to decide without opening
     * anything else: the store, the masked customer, the stored amounts, the
     * hold's own reason code and age, where the credit came from, whether an
     * accrual was ever posted (a zeroed row has none to reverse), and the
     * state a release would land in.
     *
     * @param  string|null  $reasonCode  filter on the hold's reason code
     * @param  int|null  $merchantId  filter on one store
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function list(?string $reasonCode = null, ?int $merchantId = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query()
            ->when($reasonCode !== null, fn (Builder $query) => $query->whereRaw(
                'COALESCE(hold.reason_code, transactions.reason_code) = ?',
                [$reasonCode],
            ))
            ->when($merchantId !== null, fn (Builder $query) => $query->where('transactions.merchant_id', $merchantId))
            ->orderBy('hold.created_at')
            ->orderBy('transactions.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Queue totals over EVERY hold, not the filtered page: the nav badge has
     * to be honest while an admin is looking at one store, and the filter
     * pickers have to offer reasons and stores that exist.
     *
     * @return array{total: int, reasons: list<array{reason_code: string|null, count: int}>, merchants: list<array{id: int, name: string, count: int}>}
     */
    public function summary(): array
    {
        $reasons = $this->baseQuery()
            ->selectRaw('COALESCE(hold.reason_code, transactions.reason_code) AS reason_code, COUNT(*) AS n')
            ->groupByRaw('COALESCE(hold.reason_code, transactions.reason_code)')
            ->orderByDesc('n')
            ->get();

        $merchants = DB::table('transactions')
            ->join('merchants', 'merchants.id', '=', 'transactions.merchant_id')
            ->where('transactions.state', TransactionState::OnHold->value)
            ->groupBy('merchants.id', 'merchants.name')
            ->selectRaw('merchants.id AS id, merchants.name AS name, COUNT(*) AS n')
            ->orderBy('merchants.name')
            ->get();

        return [
            'total' => (int) $reasons->sum(fn (object $row): int => (int) $row->n),
            'reasons' => $reasons
                ->map(fn (object $row): array => [
                    'reason_code' => $row->reason_code === null ? null : (string) $row->reason_code,
                    'count' => (int) $row->n,
                ])
                ->values()
                ->all(),
            'merchants' => $merchants
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => (int) $row->n,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Clears one hold, row-locked, in a single call.
     *
     * The target is derived (see releaseTarget) and, whenever it is
     * payable_unfunded, the §7 clock is stamped by the same
     * TransitionService::makePayable call that writes the state — one DB
     * transaction, one event, no window in which a payable row exists with a
     * null clock.
     *
     * A row that was ALREADY on the clock before the hold has that clock
     * RESUMED, not restarted: clock_start_at moves forward by exactly the
     * time the row spent on_hold, so due_at lands at its old value plus the
     * same freeze. The hold froze the row — the merchant could not have
     * settled it, the settlement builder would not even offer it — so the
     * review period is not charged to them; but neither is the position they
     * were already in when the review opened handed back to them. A restart
     * would give a store 15 fresh days on a debt that was overdue before
     * anyone held it, and §7's only credit control (the day-16 suspension,
     * un-done by the reinstatement sweep 30 minutes later) plus the 90-day
     * write-off horizon would slide with it — two admin clicks and a
     * defaulter is current again, without paying anything. The freeze is
     * recorded in the event meta alongside the note.
     *
     * @param  string|null  $note  free text kept verbatim on the event
     */
    public function release(Transaction $transaction, AdminUser $admin, ?string $note = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $admin, $note): Transaction {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('merchant');

            if ($locked->state !== TransactionState::OnHold) {
                throw NotOnHoldException::for($locked, 'released');
            }

            $now = CarbonImmutable::now('UTC');
            $lastHold = $this->lastHoldEvent($locked);
            $preHoldState = $lastHold?->from_state;
            $windowElapsed = self::validationWindowElapsed($locked, $now);
            $target = self::releaseTarget($locked, $preHoldState, $now);

            $resumeFrom = $target === TransactionState::PayableUnfunded
                ? self::resumedClockStart($locked, $lastHold?->created_at, $now)
                : null;

            $meta = [
                'note' => $note,
                'hold_reason_code' => $lastHold?->reason_code ?? $locked->reason_code,
                'pre_hold_state' => $preHoldState,
                'released_to' => $target->value,
                'validation_window_elapsed' => $windowElapsed,
                // True when the row was already on the clock before the hold
                // and that clock is being resumed rather than started: the
                // evidence says so, and says by how much it moved.
                'clock_resumed' => $resumeFrom !== null,
                'clock_frozen_seconds' => $resumeFrom === null
                    ? null
                    : (int) $locked->clock_start_at->diffInSeconds($resumeFrom),
            ];

            if ($target === TransactionState::PayableUnfunded) {
                $this->transitions->makePayable(
                    $locked,
                    Actor::admin($admin->getKey()),
                    self::RELEASE_REASON,
                    $meta,
                    clockStartAt: $resumeFrom,
                );

                // The customer's Pending just became Confirmed — the same
                // moment the sweeper announces when a window closes. Only on
                // the RELEASE-TO-CLOCK outcome: a release back to
                // awaiting_validation confirms nothing yet, and the sweeper
                // will speak when the window closes. Deferred to afterCommit
                // inside the service, so a rolled-back release never sends.
                $customer = $locked->customer;

                if ($customer !== null && (int) $locked->cashback_laari > 0) {
                    $this->notifications->send(NotificationTemplateKey::CashbackConfirmed, $customer, [
                        'amount' => NotificationService::money((int) $locked->cashback_laari),
                        'store' => (string) $locked->merchant?->name,
                    ]);
                }
            } else {
                $this->transitions->transition(
                    $locked,
                    $target,
                    Actor::admin($admin->getKey()),
                    self::RELEASE_REASON,
                    meta: $meta,
                );
            }

            return $locked->refresh();
        });
    }

    /**
     * Refuses one hold: the sale reverses and the accrual is mirrored from
     * the STORED integers (a zeroed row has no accrual and nothing to
     * mirror — ReversalService checks that itself).
     *
     * The reversal runs through ReversalService, the ONE §9.2 decision tree,
     * rather than a second copy of it living here: it holds the settlement
     * row lock while it decides, retires any pending memo, gives a draft
     * settlement its line back, and posts the mirror through Postings. A
     * queue that reimplemented that would drift from it.
     *
     * Two consequences of using the real tree, both deliberate:
     *
     *  - confirmed and paid rows can never appear (they are not on_hold, and
     *    the guard above refuses them 409 before the tree runs);
     *  - an outcome that is not an in-place reversal is refused and rolled
     *    back whole (HoldNotReversibleException) — this queue promises the
     *    accrual reverses, and a credit memo is not that.
     *
     * BACKDATED rows (PLAN §1) keep their decision exactly as ReversalService
     * states it, because it IS ReversalService making it: the merchant and
     * their POS vendor are refused in every state, and an ADMIN — which is
     * the only actor this service can be called with — falls through to the
     * ordinary tree. That is not a loophole this queue opened; it is the
     * "admin adjustment only" half of the same decision, and the alternative
     * (refusing here) would leave a fraud-held backdated row with no
     * resolution in the panel at all while the admin adjustment endpoint next
     * door did exactly this anyway.
     *
     * @param  string  $reason  the human reason, required, kept verbatim on the
     *                          event meta; the machine qualifier is always
     *                          REJECT_REASON so no free text ever lands in a
     *                          reason_code column the frontends translate
     */
    public function reject(Transaction $transaction, AdminUser $admin, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $admin, $reason): Transaction {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state !== TransactionState::OnHold) {
                throw NotOnHoldException::for($locked, 'rejected');
            }

            $outcome = $this->reversals->reverse(
                $locked,
                Actor::admin($admin->getKey()),
                self::REJECT_REASON,
                CarbonImmutable::now('UTC'),
                $reason,
            );

            if ($outcome->outcome !== ReversalOutcome::REVERSED) {
                throw HoldNotReversibleException::because($locked, $outcome->cause);
            }

            return $locked->refresh();
        });
    }

    /**
     * Where a release lands, and the only place that answers it — the queue
     * screen shows the same derivation before the admin confirms, so the
     * dialog can promise the 15-day clock exactly when it is about to start.
     *
     * Elapsed validation window → payable_unfunded (on the clock, from now).
     * Otherwise → the state the row was held FROM, read from history.
     *
     * `tracked` is never a target, even when the history says the row was
     * held from there. Nothing in the platform advances a tracked row —
     * ValidationSweeper only looks at awaiting_validation, and tracked exists
     * for the few statements inside CreditRecorder's own transaction between
     * the INSERT and the hop to awaiting_validation. A release to tracked
     * would strand the sale in customer-facing "Pending" forever: never
     * payable, never billed, never written off, and off the hold queue and
     * the stale-hold report the moment it left on_hold. That is reachable on
     * real data — every legacy `stale_timestamp` hold was placed straight out
     * of tracked, and one whose store later had its validation_window_days
     * widened lands back inside its window on release — so tracked collapses
     * to awaiting_validation, one hop further along the SAME road the row was
     * on, which the §7 sweeper picks up on its own when the window closes.
     *
     * The same collapse covers a hold with no readable pre-hold state
     * (hand-made production data, an event history that predates the
     * machine): awaiting_validation is the only pre-hold state a row inside
     * its validation window can legitimately have had.
     */
    public static function releaseTarget(Transaction $transaction, ?string $preHoldState, CarbonImmutable $now): TransactionState
    {
        if (self::validationWindowElapsed($transaction, $now)) {
            return TransactionState::PayableUnfunded;
        }

        $target = $preHoldState === null ? null : TransactionState::tryFrom($preHoldState);

        return match ($target) {
            TransactionState::AwaitingValidation,
            TransactionState::PayableUnfunded => $target,
            default => TransactionState::AwaitingValidation,
        };
    }

    /**
     * Where a resumed §7 clock restarts from, or null when there is no clock
     * to resume and one simply starts now.
     *
     * A row that was payable before the hold keeps the position it had: the
     * clock advances by exactly the frozen interval (hold event → release),
     * so a row 10 days overdue when the review opened is still 10 days
     * overdue when it closes, and a row with 4 days left still has 4. Only a
     * row whose clock never ran (held out of tracked/awaiting_validation,
     * released after its validation window closed) gets a clock starting now
     * — that is the ordinary day-0 stamp, not a reset.
     *
     * Clamped to now, so hand-made data whose hold event predates its own
     * clock_start_at can never stamp a start date in the future.
     *
     * Public because the queue screen states the consequence before the admin
     * confirms: "resumes" and "starts" are different promises, and both must
     * come from this one derivation rather than a second copy in a resource.
     */
    public static function resumedClockStart(Transaction $transaction, ?CarbonImmutable $heldAt, CarbonImmutable $now): ?CarbonImmutable
    {
        $clockStart = $transaction->clock_start_at;

        if ($clockStart === null || $heldAt === null) {
            return null;
        }

        $frozenSeconds = max(0, (int) $heldAt->diffInSeconds($now));
        $resumed = CarbonImmutable::parse($clockStart)->utc()->addSeconds($frozenSeconds);

        return $resumed->greaterThan($now) ? $now : $resumed;
    }

    /**
     * §7 day 0: has the merchant's refund window closed on this sale? Same
     * predicate as ValidationSweeper, evaluated per row so the queue and the
     * sweeper can never disagree about which side of day 0 a hold sits on.
     * The merchant relation must be loaded.
     */
    public static function validationWindowElapsed(Transaction $transaction, CarbonImmutable $now): bool
    {
        $windowDays = (int) $transaction->merchant->validation_window_days;

        return $transaction->occurred_at->addDays($windowDays)->lessThanOrEqualTo($now);
    }

    /** The stored accrual a reject would have to mirror; 0 for a zeroed row. */
    public static function accruedLaari(Transaction $transaction): int
    {
        return (int) $transaction->cashback_laari
            + (int) $transaction->fee_laari
            + (int) $transaction->fee_gst_laari;
    }

    /**
     * The newest on_hold event: the hold's own reason, its age, and the state
     * it was held from. The append-only history is the only record of the
     * last two — the row itself keeps just the current state.
     */
    private function lastHoldEvent(Transaction $transaction): ?object
    {
        return $transaction->events()
            ->where('to_state', TransactionState::OnHold->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Held transactions with their hold event joined on — one LATERAL pass
     * for the newest on_hold row per transaction instead of a query per row.
     */
    private function baseQuery(): QueryBuilder
    {
        return DB::table('transactions')
            ->leftJoinLateral(
                DB::table('transaction_events')
                    ->select(['created_at', 'from_state', 'reason_code', 'actor_type', 'actor_id'])
                    ->whereColumn('transaction_events.transaction_id', 'transactions.id')
                    ->where('to_state', TransactionState::OnHold->value)
                    ->orderByDesc('id')
                    ->limit(1),
                'hold',
            )
            ->where('transactions.state', TransactionState::OnHold->value);
    }

    /**
     * The queue read model: the held rows themselves, each carrying its hold
     * event's timestamp, reason, actor and pre-hold state as columns.
     *
     * @return Builder<Transaction>
     */
    private function query(): Builder
    {
        return Transaction::query()
            ->leftJoinLateral(
                DB::table('transaction_events')
                    ->select(['created_at', 'from_state', 'reason_code', 'actor_type', 'actor_id'])
                    ->whereColumn('transaction_events.transaction_id', 'transactions.id')
                    ->where('to_state', TransactionState::OnHold->value)
                    ->orderByDesc('id')
                    ->limit(1),
                'hold',
            )
            ->where('transactions.state', TransactionState::OnHold->value)
            ->select([
                'transactions.*',
                'hold.created_at as held_at',
                'hold.from_state as pre_hold_state',
                'hold.reason_code as hold_reason_code',
                'hold.actor_type as held_by_type',
                'hold.actor_id as held_by_id',
            ])
            // validation_window_days rides along because releaseTarget needs
            // it per row; the masked customer name and code come from the
            // customer relation, and nothing else about the customer does.
            ->with([
                'merchant:id,name,slug,validation_window_days',
                'customer:id,customer_code,name',
            ]);
    }
}
