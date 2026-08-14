<?php

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\InvalidTransitionException;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new TransitionService;
});

afterEach(function () {
    Carbon::setTestNow();
});

it('walks the full happy path writing one event per hop', function () {
    $transaction = Transaction::factory()->create();

    $this->service->recordCreated($transaction, Actor::pos(3));
    $this->service->startValidation($transaction, Actor::system());
    $this->service->makePayable($transaction, Actor::system());
    $this->service->confirm($transaction, Actor::system());
    $this->service->markPaid($transaction, Actor::admin(1));

    expect($transaction->refresh()->state)->toBe(TransactionState::Paid);

    $events = $transaction->events()->orderBy('id')->get();

    expect($events)->toHaveCount(5)
        ->and($events->map(fn ($event) => [$event->from_state, $event->to_state])->all())->toBe([
            [null, 'tracked'],
            ['tracked', 'awaiting_validation'],
            ['awaiting_validation', 'payable_unfunded'],
            ['payable_unfunded', 'confirmed'],
            ['confirmed', 'paid'],
        ]);
});

it('records actor and reason on the event row', function () {
    $transaction = Transaction::factory()->create(['state' => 'payable_unfunded']);

    $event = $this->service->hold($transaction, Actor::admin(7), 'fraud_review', ['rule' => 'velocity']);

    expect($event->actor_type)->toBe('admin')
        ->and($event->actor_id)->toBe(7)
        ->and($event->reason_code)->toBe('fraud_review')
        ->and($event->meta)->toBe(['rule' => 'velocity'])
        ->and($transaction->refresh()->reason_code)->toBe('fraud_review');
});

it('reverses from payable_unfunded', function () {
    $transaction = Transaction::factory()->create(['state' => 'payable_unfunded']);

    $event = $this->service->reverse($transaction, Actor::pos(9), 'customer_refund');

    expect($transaction->refresh()->state)->toBe(TransactionState::Reversed)
        ->and($event->from_state)->toBe('payable_unfunded')
        ->and($event->to_state)->toBe('reversed')
        ->and($event->reason_code)->toBe('customer_refund');
});

it('refuses to reverse a confirmed transaction, pointing at adjustments', function () {
    $transaction = Transaction::factory()->create(['state' => 'confirmed']);

    expect(fn () => $this->service->reverse($transaction, Actor::admin(1), 'customer_refund'))
        ->toThrow(InvalidTransitionException::class, 'adjustments');

    expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($transaction->events()->count())->toBe(0);
});

it('reaches written_off only from payable_unfunded', function () {
    foreach (['tracked', 'awaiting_validation', 'on_hold', 'confirmed', 'paid', 'reversed'] as $state) {
        $transaction = Transaction::factory()->create(['state' => $state]);

        expect(fn () => $this->service->writeOff($transaction, Actor::system(), 'past_due_90d'))
            ->toThrow(InvalidTransitionException::class);

        expect($transaction->events()->count())->toBe(0);
    }

    $transaction = Transaction::factory()->create(['state' => 'payable_unfunded']);
    $event = $this->service->writeOff($transaction, Actor::system(), 'past_due_90d');

    expect($transaction->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and($event->from_state)->toBe('payable_unfunded')
        ->and($event->to_state)->toBe('written_off');
});

it('rejects every transition out of a terminal state', function () {
    foreach (['paid', 'reversed', 'written_off'] as $terminal) {
        $transaction = Transaction::factory()->create(['state' => $terminal]);

        foreach (TransactionState::cases() as $to) {
            expect(fn () => $this->service->transition($transaction, $to, Actor::system()))
                ->toThrow(InvalidTransitionException::class);
        }

        expect($transaction->refresh()->state->value)->toBe($terminal)
            ->and($transaction->events()->count())->toBe(0);
    }
});

it('releases a hold back to the pre-hold state read from the event history', function () {
    $transaction = Transaction::factory()->create(['state' => 'awaiting_validation']);

    $this->service->hold($transaction, Actor::admin(7), 'fraud_review');
    expect($transaction->refresh()->state)->toBe(TransactionState::OnHold);

    $this->service->release($transaction, Actor::admin(7));

    expect($transaction->refresh()->state)->toBe(TransactionState::AwaitingValidation)
        ->and($transaction->reason_code)->toBeNull();

    $last = $transaction->events()->orderByDesc('id')->first();
    expect($last->from_state)->toBe('on_hold')
        ->and($last->to_state)->toBe('awaiting_validation');
});

it('releases to the last pre-hold state after repeated holds', function () {
    $transaction = Transaction::factory()->create(['state' => 'awaiting_validation']);

    $this->service->hold($transaction, Actor::admin(1), 'fraud_review');
    $this->service->release($transaction, Actor::admin(1));
    $this->service->makePayable($transaction, Actor::system());
    $this->service->hold($transaction, Actor::admin(1), 'dispute');

    $this->service->release($transaction, Actor::admin(1));

    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded);
});

it('refuses to release a transaction that is not on hold', function () {
    $transaction = Transaction::factory()->create(['state' => 'awaiting_validation']);

    expect(fn () => $this->service->release($transaction, Actor::admin(1)))
        ->toThrow(InvalidTransitionException::class);
});

it('sets due_at exactly 15 days after the clock start, stored UTC', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-14T10:30:00Z'));

    $transaction = Transaction::factory()->create(['state' => 'awaiting_validation']);
    $this->service->makePayable($transaction, Actor::system());

    $transaction->refresh();

    expect($transaction->clock_start_at->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-08-14T10:30:00Z')->getTimestamp())
        ->and($transaction->due_at->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-08-29T10:30:00Z')->getTimestamp())
        ->and($transaction->due_at->getTimestamp() - $transaction->clock_start_at->getTimestamp())
        ->toBe(15 * 86400);
});

it('evaluates due_at in the configured business timezone, not a hardcoded fallback', function () {
    // A DST-observing override makes the config value observable: London
    // gains an hour on 2026-03-29, so a 15-day clock started 2026-03-20 lands
    // one hour earlier in UTC than pure UTC arithmetic would.
    config(['app.business_timezone' => 'Europe/London']);
    Carbon::setTestNow(CarbonImmutable::parse('2026-03-20T12:00:00Z'));

    $transaction = Transaction::factory()->create(['state' => 'awaiting_validation']);
    $this->service->makePayable($transaction, Actor::system());

    expect($transaction->refresh()->due_at->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-04-04T11:00:00Z')->getTimestamp());
});

it('writes exactly one event per transition and none on failure', function () {
    $transaction = Transaction::factory()->create();

    $this->service->recordCreated($transaction, Actor::pos(2));

    $transitions = 0;
    $this->service->startValidation($transaction, Actor::system());
    $transitions++;
    $this->service->hold($transaction, Actor::admin(4), 'velocity_check');
    $transitions++;
    $this->service->release($transaction, Actor::admin(4));
    $transitions++;
    $this->service->makePayable($transaction, Actor::system());
    $transitions++;
    $this->service->confirm($transaction, Actor::system());
    $transitions++;
    $this->service->markPaid($transaction, Actor::admin(4));
    $transitions++;

    expect($transaction->events()->count())->toBe($transitions + 1);

    // A rejected transition must leave the history untouched.
    expect(fn () => $this->service->transition($transaction, TransactionState::Reversed, Actor::system()))
        ->toThrow(InvalidTransitionException::class);
    expect($transaction->events()->count())->toBe($transitions + 1);

    // The chain is contiguous: every hop starts where the previous ended.
    $events = $transaction->events()->orderBy('id')->get();
    expect($events->first()->from_state)->toBeNull();
    $events->sliding(2)->each(function ($pair) {
        [$previous, $current] = $pair->values();
        expect($current->from_state)->toBe($previous->to_state);
    });
});

it('refuses a duplicate creation event', function () {
    $transaction = Transaction::factory()->create();

    $this->service->recordCreated($transaction, Actor::pos(2));

    expect(fn () => $this->service->recordCreated($transaction, Actor::pos(2)))
        ->toThrow(InvalidTransitionException::class);
    expect($transaction->events()->count())->toBe(1);
});
