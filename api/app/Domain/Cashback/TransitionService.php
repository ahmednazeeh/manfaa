<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\MerchantMoneyCache;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Referrals\ReferralService;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The §6 transaction state machine. Every state change happens inside one DB
 * transaction, under a row lock, and writes exactly one transaction_events
 * row — there is no path to a silent state mutation.
 */
final class TransitionService
{
    /**
     * Optional so `new TransitionService` keeps working; PlatformConfig is
     * dependency-free, so a null simply means "instantiate one on demand".
     */
    public function __construct(private readonly ?PlatformConfig $platformConfig = null) {}

    /**
     * Allowed transitions, keyed by from-state. paid, reversed and
     * written_off are terminal; confirmed can only be paid — corrections
     * after confirmation are adjustments (§13), never reversals.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED = [
        'tracked' => ['awaiting_validation', 'on_hold', 'reversed'],
        // `confirmed` is reachable here for MARKETPLACE rewards only: the
        // platform already holds the customer's money, so there is nothing
        // for a merchant to fund and `payable_unfunded` would be a lie in
        // the data — a future reader summing it to ask "what do merchants
        // owe us" would over-count. ValidationSweeper is the only caller
        // that takes this path, and only for that origin.
        'awaiting_validation' => ['payable_unfunded', 'confirmed', 'on_hold', 'reversed'],
        'payable_unfunded' => ['confirmed', 'on_hold', 'reversed', 'written_off'],
        'on_hold' => ['tracked', 'awaiting_validation', 'payable_unfunded', 'reversed'],
        'confirmed' => ['paid'],
        'paid' => [],
        'reversed' => [],
        'written_off' => [],
    ];

    /**
     * Moves the transaction to $to and writes the event, atomically. The row
     * is locked and the from-state re-validated after the lock, so a
     * concurrent transition loses cleanly instead of silently double-firing.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $attributes  extra transaction columns written atomically with the state
     * @param  bool  $stampReasonOnRow  false records the reason on the EVENT only —
     *                                  for transition annotations (e.g. auto_validation_window)
     *                                  that do not qualify the resulting state
     */
    public function transition(
        Transaction $transaction,
        TransactionState $to,
        Actor $actor,
        ?string $reasonCode = null,
        array $meta = [],
        array $attributes = [],
        bool $stampReasonOnRow = true,
    ): TransactionEvent {
        $this->assertAllowed($transaction, $transaction->state, $to);

        return DB::transaction(function () use ($transaction, $to, $actor, $reasonCode, $meta, $attributes, $stampReasonOnRow): TransactionEvent {
            Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first();
            $transaction->refresh();

            $from = $transaction->state;
            $this->assertAllowed($transaction, $from, $to);

            $transaction->forceFill([...$attributes, 'state' => $to]);

            if ($reasonCode !== null && $stampReasonOnRow) {
                $transaction->reason_code = $reasonCode;
            }

            $transaction->save();

            // Every state change moves what a money read would answer; the
            // bump defers itself to after this transaction commits.
            MerchantMoneyCache::bump((int) $transaction->merchant_id);

            // Referral programme (owner, 2026-08-23): entering confirmed —
            // the moment spend becomes merchant-funded — may have carried
            // this customer past the threshold. AFTER COMMIT — the award opens its own transaction
            // and must judge committed spend, never a state a rollback could
            // take away. O(1) for the never-referred: the check starts with
            // one primary-key lookup, and no SUM runs unless it hits.
            // Swallowed on failure like a notification would be — the safety
            // net command re-runs the same check daily, and a referral hiccup
            // is never a reason to fail a money transition.
            if ($transaction->customer_id !== null && $to === TransactionState::Confirmed) {
                $customerId = (int) $transaction->customer_id;

                DB::afterCommit(function () use ($customerId): void {
                    try {
                        app(ReferralService::class)->checkCustomer($customerId);
                    } catch (Throwable $exception) {
                        Log::warning('Referral award check failed.', [
                            'customer_id' => $customerId,
                            'exception' => $exception->getMessage(),
                        ]);
                    }
                });
            }

            return $this->writeEvent($transaction, $from, $to, $actor, $reasonCode, $meta);
        });
    }

    /**
     * Writes the creation event (null → tracked). Creating the row itself
     * belongs to the credit services; this makes the birth auditable like
     * every later hop.
     */
    public function recordCreated(Transaction $transaction, Actor $actor, ?string $reasonCode = null, array $meta = []): TransactionEvent
    {
        if ($transaction->state !== TransactionState::Tracked) {
            throw new InvalidTransitionException(sprintf(
                'Transaction #%d is %s — a creation event can only be recorded while tracked.',
                $transaction->getKey(),
                $transaction->state->value,
            ));
        }

        if ($transaction->events()->exists()) {
            throw new InvalidTransitionException(sprintf(
                'Transaction #%d already has events; the creation event must be the first.',
                $transaction->getKey(),
            ));
        }

        MerchantMoneyCache::bump((int) $transaction->merchant_id);

        return $this->writeEvent($transaction, null, TransactionState::Tracked, $actor, $reasonCode, $meta);
    }

    public function startValidation(Transaction $transaction, Actor $actor): TransactionEvent
    {
        return $this->transition($transaction, TransactionState::AwaitingValidation, $actor);
    }

    /**
     * Starts the settlement clock (§7, 15 days unless the admin-managed
     * settlement_due_days setting says otherwise): due_at is evaluated in
     * the business timezone, stored UTC, and echoed into the event meta as
     * evidence.
     *
     * $reasonCode qualifies the resulting payable state and is stamped on the
     * row — used by the backdated-credit path (PLAN §1), where a sale older
     * than the validation window becomes payable IMMEDIATELY and the row must
     * say so (`backdated_final`), and by the hold-review release (§13b), where
     * it says `admin_release`. The ordinary window-close path passes null: a
     * clean payable sale has no state qualifier.
     *
     * $meta is merged UNDER the clock evidence, so a caller can attach its own
     * context (the releasing admin's note, say) but can never overwrite the
     * recorded clock_start_at/due_at with a value it made up. This is the only
     * way into payable_unfunded that stamps the clock, and every caller must
     * use it: a payable row with a null clock_start_at is invisible to the §7
     * escalation ladder, the write-off sweep and the due-date columns — the
     * exact defect PLAN §13b records against an earlier manual hold release.
     *
     * $clockStartAt RESUMES a clock instead of starting one: the hold-review
     * release passes the pre-hold clock_start_at advanced by the frozen
     * interval, so a row that was already overdue when a fraud review opened
     * is still overdue when it closes (§13b). Null — every other caller —
     * means day 0 is NOW. It is not a way to invent a start date: the only
     * legitimate value is a clock this row was already on, and the caller
     * must never pass a future instant.
     *
     * @param  array<string, mixed>  $meta
     */
    public function makePayable(
        Transaction $transaction,
        Actor $actor,
        ?string $reasonCode = null,
        array $meta = [],
        ?CarbonImmutable $clockStartAt = null,
    ): TransactionEvent {
        $clockStart = $clockStartAt ?? CarbonImmutable::now('UTC');
        $dueAt = $clockStart
            ->setTimezone($this->businessTimezone())
            ->addDays(($this->platformConfig ?? new PlatformConfig)->settlementDueDays())
            ->setTimezone('UTC');

        return $this->transition(
            $transaction,
            TransactionState::PayableUnfunded,
            $actor,
            $reasonCode,
            meta: [...$meta, 'clock_start_at' => $clockStart->toIso8601String(), 'due_at' => $dueAt->toIso8601String()],
            attributes: ['clock_start_at' => $clockStart, 'due_at' => $dueAt],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function hold(Transaction $transaction, Actor $actor, ?string $reasonCode = null, array $meta = []): TransactionEvent
    {
        return $this->transition($transaction, TransactionState::OnHold, $actor, $reasonCode, $meta);
    }

    /**
     * Releases a hold back to whatever state the transaction held from, read
     * from the last on_hold event in the append-only history. The hold's
     * reason_code is cleared from the row; the event history keeps it.
     */
    public function release(Transaction $transaction, Actor $actor): TransactionEvent
    {
        $lastHold = $transaction->events()
            ->where('to_state', TransactionState::OnHold->value)
            ->orderByDesc('id')
            ->first();

        if ($lastHold?->from_state === null) {
            throw new InvalidTransitionException(sprintf(
                'Transaction #%d has no hold event to release from.',
                $transaction->getKey(),
            ));
        }

        return $this->transition(
            $transaction,
            TransactionState::from($lastHold->from_state),
            $actor,
            attributes: ['reason_code' => null],
        );
    }

    public function confirm(Transaction $transaction, Actor $actor): TransactionEvent
    {
        return $this->transition($transaction, TransactionState::Confirmed, $actor);
    }

    public function markPaid(Transaction $transaction, Actor $actor): TransactionEvent
    {
        return $this->transition($transaction, TransactionState::Paid, $actor);
    }

    /**
     * Reversal is permitted only before confirmation. From confirmed onward
     * the guard throws explicitly — corrections are adjustments (§13).
     *
     * @param  array<string, mixed>  $meta
     */
    public function reverse(Transaction $transaction, Actor $actor, ?string $reasonCode = null, array $meta = []): TransactionEvent
    {
        if (in_array($transaction->state, [TransactionState::Confirmed, TransactionState::Paid], true)) {
            throw InvalidTransitionException::reverseAfterConfirmation($transaction);
        }

        return $this->transition($transaction, TransactionState::Reversed, $actor, $reasonCode, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function writeOff(Transaction $transaction, Actor $actor, ?string $reasonCode = null, array $meta = []): TransactionEvent
    {
        return $this->transition($transaction, TransactionState::WrittenOff, $actor, $reasonCode, $meta);
    }

    private function assertAllowed(Transaction $transaction, TransactionState $from, TransactionState $to): void
    {
        if (! in_array($to->value, self::ALLOWED[$from->value], true)) {
            throw InvalidTransitionException::between($transaction, $from, $to);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeEvent(
        Transaction $transaction,
        ?TransactionState $from,
        TransactionState $to,
        Actor $actor,
        ?string $reasonCode,
        array $meta,
    ): TransactionEvent {
        return $transaction->events()->create([
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'actor_type' => $actor->actorType,
            'actor_id' => $actor->actorId,
            'reason_code' => $reasonCode,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function businessTimezone(): string
    {
        return (string) config('app.business_timezone', 'Indian/Maldives');
    }
}
