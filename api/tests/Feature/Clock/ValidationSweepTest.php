<?php

use App\Domain\Cashback\TransactionState;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('sweeps a transaction onto the clock only once its merchant window has elapsed', function () {
    $occurredAt = CarbonImmutable::parse('2026-08-01T10:00:00+00:00');
    Carbon::setTestNow($occurredAt);

    $threeDayMerchant = Merchant::factory()->create(['validation_window_days' => 3]);
    $sevenDayMerchant = Merchant::factory()->create(['validation_window_days' => 7]);

    $threeDay = Transaction::factory()->for($threeDayMerchant)->create([
        'state' => 'awaiting_validation',
        'occurred_at' => $occurredAt,
    ]);
    $sevenDay = Transaction::factory()->for($sevenDayMerchant)->create([
        'state' => 'awaiting_validation',
        'occurred_at' => $occurredAt,
    ]);

    // One hour before the 3-day window closes: nothing moves.
    Carbon::setTestNow($occurredAt->addDays(3)->subHour());
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    expect($threeDay->refresh()->state)->toBe(TransactionState::AwaitingValidation)
        ->and($sevenDay->refresh()->state)->toBe(TransactionState::AwaitingValidation);

    // Exactly 3 days after occurred_at: the 3-day merchant sweeps, 7-day holds.
    $sweepAt = $occurredAt->addDays(3);
    Carbon::setTestNow($sweepAt);
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    expect($threeDay->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($sevenDay->refresh()->state)->toBe(TransactionState::AwaitingValidation);

    // due_at is exactly clock_start + 15 days (business tz has no DST, so the
    // instant matches the plain 15-day duration), stamped by makePayable.
    expect($threeDay->clock_start_at->equalTo($sweepAt))->toBeTrue()
        ->and($threeDay->due_at->equalTo($sweepAt->addDays(15)))->toBeTrue();

    $event = $threeDay->events()->where('to_state', 'payable_unfunded')->get();
    expect($event)->toHaveCount(1)
        ->and($event->first()->actor_type)->toBe('system')
        ->and($event->first()->meta['due_at'])->toBe($sweepAt->addDays(15)->toIso8601String());

    // Day 7: the second merchant's window closes too.
    Carbon::setTestNow($occurredAt->addDays(7));
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    expect($sevenDay->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($sevenDay->due_at->equalTo($occurredAt->addDays(7)->addDays(15)))->toBeTrue();
});

it('is idempotent — re-running sweeps nothing twice and writes no extra events', function () {
    $occurredAt = CarbonImmutable::parse('2026-08-01T10:00:00+00:00');

    $merchant = Merchant::factory()->create(['validation_window_days' => 3]);
    $transaction = Transaction::factory()->for($merchant)->create([
        'state' => 'awaiting_validation',
        'occurred_at' => $occurredAt,
    ]);

    Carbon::setTestNow($occurredAt->addDays(4));
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($transaction->events()->where('to_state', 'payable_unfunded')->count())->toBe(1);
});

it('leaves transactions in other states alone', function () {
    $occurredAt = CarbonImmutable::parse('2026-08-01T10:00:00+00:00');

    $merchant = Merchant::factory()->create(['validation_window_days' => 3]);
    $tracked = Transaction::factory()->for($merchant)->create([
        'state' => 'tracked',
        'occurred_at' => $occurredAt,
    ]);
    $onHold = Transaction::factory()->for($merchant)->create([
        'state' => 'on_hold',
        'occurred_at' => $occurredAt,
    ]);

    Carbon::setTestNow($occurredAt->addDays(10));
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    expect($tracked->refresh()->state)->toBe(TransactionState::Tracked)
        ->and($onHold->refresh()->state)->toBe(TransactionState::OnHold);
});
