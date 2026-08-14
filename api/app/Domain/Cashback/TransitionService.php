<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Transaction;
use App\Models\TransactionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The §6 transaction state machine. Every state change happens inside one DB
 * transaction, under a row lock, and writes exactly one transaction_events
 * row — there is no path to a silent state mutation.
 */
final class TransitionService
{
    private const int SETTLEMENT_CLOCK_DAYS = 15;

    /**
     * Allowed transitions, keyed by from-state. paid, reversed and
     * written_off are terminal; confirmed can only be paid — corrections
     * after confirmation are adjustments (§13), never reversals.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED = [
        'tracked' => ['awaiting_validation', 'on_hold', 'reversed'],
        'awaiting_validation' => ['payable_unfunded', 'on_hold', 'reversed'],
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
     */
    public function transition(
        Transaction $transaction,
        TransactionState $to,
        Actor $actor,
        ?string $reasonCode = null,
        array $meta = [],
        array $attributes = [],
    ): TransactionEvent {
        $this->assertAllowed($transaction, $transaction->state, $to);

        return DB::transaction(function () use ($transaction, $to, $actor, $reasonCode, $meta, $attributes): TransactionEvent {
            Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first();
            $transaction->refresh();

            $from = $transaction->state;
            $this->assertAllowed($transaction, $from, $to);

            $transaction->forceFill([...$attributes, 'state' => $to]);

            if ($reasonCode !== null) {
                $transaction->reason_code = $reasonCode;
            }

            $transaction->save();

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

        return $this->writeEvent($transaction, null, TransactionState::Tracked, $actor, $reasonCode, $meta);
    }

    public function startValidation(Transaction $transaction, Actor $actor): TransactionEvent
    {
        return $this->transition($transaction, TransactionState::AwaitingValidation, $actor);
    }

    /**
     * Starts the 15-day settlement clock: due_at is evaluated in the business
     * timezone, stored UTC, and echoed into the event meta as evidence.
     */
    public function makePayable(Transaction $transaction, Actor $actor): TransactionEvent
    {
        $clockStart = CarbonImmutable::now('UTC');
        $dueAt = $clockStart
            ->setTimezone($this->businessTimezone())
            ->addDays(self::SETTLEMENT_CLOCK_DAYS)
            ->setTimezone('UTC');

        return $this->transition(
            $transaction,
            TransactionState::PayableUnfunded,
            $actor,
            meta: ['clock_start_at' => $clockStart->toIso8601String(), 'due_at' => $dueAt->toIso8601String()],
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
