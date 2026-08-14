<?php

use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{0: Merchant, 1: Transaction, 2: CarbonImmutable}
 */
function escalationFixture(): array
{
    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();

    $merchant = Merchant::factory()->create();
    $transaction = Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);

    return [$merchant, $transaction, $clockStart];
}

it('sends exactly one notice per ladder step and never repeats within the cycle', function () {
    [$merchant, , $clockStart] = escalationFixture();

    // Day 9: below every threshold — silence.
    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:escalate')->assertExitCode(0);
    expect(MerchantNotice::query()->count())->toBe(0);

    // Day 10 boundary: one reminder, and only one.
    Carbon::setTestNow($clockStart->addDays(10));
    $this->artisan('manfaa:escalate')->assertExitCode(0);
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    $reminders = MerchantNotice::query()->where('type', 'reminder_day10')->get();
    expect(MerchantNotice::query()->count())->toBe(1)
        ->and($reminders)->toHaveCount(1)
        ->and($reminders->first()->merchant_id)->toBe($merchant->id)
        ->and($reminders->first()->channel)->toBe('log')
        ->and($reminders->first()->payload['days_since_clock_start'])->toBe(10)
        ->and($reminders->first()->payload['outstanding_laari'])->toBe(2_750);

    // Day 13 boundary: one urgent reminder; re-running adds none.
    Carbon::setTestNow($clockStart->addDays(13));
    $this->artisan('manfaa:escalate')->assertExitCode(0);
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    expect(MerchantNotice::query()->count())->toBe(2)
        ->and(MerchantNotice::query()->where('type', 'urgent_day13')->count())->toBe(1);

    // Day 15 boundary: one payment-due notice; re-running adds none.
    Carbon::setTestNow($clockStart->addDays(15));
    $this->artisan('manfaa:escalate')->assertExitCode(0);
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    expect(MerchantNotice::query()->count())->toBe(3)
        ->and(MerchantNotice::query()->where('type', 'due_day15')->count())->toBe(1)
        ->and(MerchantNotice::query()->pluck('type')->sort()->values()->all())
        ->toBe(['due_day15', 'reminder_day10', 'urgent_day13']);
});

it('measures the ladder from the oldest unfunded payable', function () {
    [$merchant, , $clockStart] = escalationFixture();

    // A younger debt joins the pile — the ladder still runs off the oldest.
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart->addDays(8),
        'due_at' => $clockStart->addDays(8)->addDays(15),
        'cashback_laari' => 1_000,
        'fee_laari' => 375,
        'fee_gst_laari' => 0,
    ]);

    Carbon::setTestNow($clockStart->addDays(13));
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    $notice = MerchantNotice::query()->sole();
    expect($notice->type)->toBe('urgent_day13')
        ->and($notice->payload['open_transactions'])->toBe(2)
        ->and($notice->payload['outstanding_laari'])->toBe(2_750 + 1_375);
});

it('counts business-calendar days: the day-15 notice precedes the day-16 suspension for a late-day clock start', function () {
    // The validation sweep is hourly, so clocks start at any time of day —
    // 14:00 MVT is AFTER the ladder's 09:00 daily run.
    $clockStart = CarbonImmutable::parse('2026-08-01T14:00:00+05:00')->utc();

    $merchant = Merchant::factory()->create();
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);

    // The 09:00 run on the tenth business day: only 9.79 duration-days have
    // elapsed, but §7's "day 10" is a calendar day — the reminder fires
    // today, not tomorrow.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-11T09:00:00+05:00'));
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    $reminder = MerchantNotice::query()->where('type', 'reminder_day10')->sole();
    expect($reminder->payload['days_since_clock_start'])->toBe(10);

    // Day 15's 09:00 run — before due_at (14:00 that day) and before the
    // day-16 00:15 suspension sweep: payment is demanded first, on record.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-16T09:00:00+05:00'));
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    $due = MerchantNotice::query()->where('type', 'due_day15')->sole();

    // Day 16, 00:15 business time: the automatic suspension follows it.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-17T00:15:00+05:00'));
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    $suspended = MerchantNotice::query()->where('type', 'suspended')->sole();

    expect($merchant->refresh()->status)->toBe('suspended')
        ->and($due->sent_at->lessThan($suspended->sent_at))->toBeTrue();
});

it('fires afresh in a new due-cycle — old notices do not suppress a later debt', function () {
    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();
    $merchant = Merchant::factory()->create();

    // Evidence of a previous, cleared cycle: a reminder sent long before this
    // cycle's clock started.
    MerchantNotice::query()->create([
        'merchant_id' => $merchant->id,
        'type' => 'reminder_day10',
        'channel' => 'log',
        'payload' => null,
        'sent_at' => $clockStart->subDays(30),
    ]);

    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
    ]);

    Carbon::setTestNow($clockStart->addDays(10));
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    // The dedupe window is scoped to notices sent since this cycle's clock
    // start, so the new cycle earns its own reminder.
    expect(MerchantNotice::query()->where('type', 'reminder_day10')->count())->toBe(2);
});
