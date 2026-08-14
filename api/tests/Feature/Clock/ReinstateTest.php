<?php

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Domain\Standing\NoticeRecorder;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('reinstates an automatically suspended merchant once the overdue payables clear', function () {
    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();
    Carbon::setTestNow($clockStart->addDays(16));

    $merchant = Merchant::factory()->create();

    $overdue = Transaction::factory()->count(2)->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
    ]);

    // Suspended through the real automatic control, notice recorded.
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect($merchant->refresh()->status)->toBe('suspended');

    // Debt still overdue: reinstatement must refuse.
    $this->artisan('manfaa:reinstate')->assertExitCode(0);
    expect($merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(0);

    // The settlement path clears the debt — simulated here by confirming the
    // transactions through the state machine, exactly what allocation does.
    $transitions = app(TransitionService::class);
    foreach ($overdue as $transaction) {
        $transitions->confirm($transaction, Actor::system());
    }

    $this->artisan('manfaa:reinstate')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('active');

    $notice = MerchantNotice::query()->where('type', 'reinstated')->sole();
    expect($notice->merchant_id)->toBe($merchant->id);

    // Re-running reinstates nothing twice.
    $this->artisan('manfaa:reinstate')->assertExitCode(0);
    expect(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(1);
});

it('reinstates when the remaining payables are not yet overdue', function () {
    $now = CarbonImmutable::parse('2026-08-20T12:00:00+00:00');
    Carbon::setTestNow($now);

    $merchant = Merchant::factory()->suspended()->create();
    app(NoticeRecorder::class)->record($merchant->id, 'suspended', []);

    // A fresh debt inside its 15 days is no ground to stay suspended.
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $now->subDay(),
        'due_at' => $now->subDay()->addDays(15),
    ]);

    $this->artisan('manfaa:reinstate')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('active')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(1);
});

it('never reinstates a merchant whose overdue debt was cleared by the 90-day write-off', function () {
    $clockStart = CarbonImmutable::parse('2026-05-01T09:00:00+05:00')->utc();
    Carbon::setTestNow($clockStart->addDays(16));

    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create();
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);

    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect($merchant->refresh()->status)->toBe('suspended');

    // Day 105+: the write-off clears the payable_unfunded rows — by DEFAULT,
    // not by payment. The 01:30 reinstate sweep must not follow it.
    Carbon::setTestNow($clockStart->addDays(15)->addDays(91));
    $this->artisan('manfaa:write-off')->assertExitCode(0);
    $this->artisan('manfaa:reinstate')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(0);
});

it('never reverses a suspension it did not itself impose', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+00:00'));

    // Admin-imposed (e.g. fraud) suspension: status set by hand, no
    // 'suspended' notice from the automatic control — and no overdue debt.
    $merchant = Merchant::factory()->suspended()->create();

    $this->artisan('manfaa:reinstate')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->count())->toBe(0);
});
