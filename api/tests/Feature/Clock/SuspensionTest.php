<?php

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\MerchantNotActiveException;
use App\Domain\Money\Laari;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('suspends a merchant on day 16, records the notice, and cashback creation stops', function () {
    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();

    $merchant = Merchant::factory()->create();
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => $clockStart->subYear(),
        'effective_to' => null,
    ]);
    $user = MerchantUser::factory()->for($merchant)->create();
    $customer = Customer::factory()->create();

    $overdue = Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);

    // Day 14: due_at not yet passed — nothing happens.
    Carbon::setTestNow($clockStart->addDays(14));
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect($merchant->refresh()->status)->toBe('active');

    // Day 16: past due — automatic suspension with a recorded notice.
    Carbon::setTestNow($clockStart->addDays(16));
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('suspended');

    $notice = MerchantNotice::query()->where('type', 'suspended')->sole();
    expect($notice->merchant_id)->toBe($merchant->id)
        ->and($notice->payload['overdue_transactions'])->toBe(1)
        ->and($notice->payload['overdue_laari'])->toBe(2_750)
        ->and($notice->payload['oldest_due_at'])->toBe($overdue->due_at->toIso8601String());

    // Re-running suspends nothing twice and writes no duplicate notice.
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect(MerchantNotice::query()->where('type', 'suspended')->count())->toBe(1);

    // The effect: cashback creation now refuses this merchant.
    expect(fn () => app(ManualCreditService::class)->credit(
        $merchant->refresh(),
        $user,
        $customer->customer_code,
        'INV-AFTER-SUSPENSION',
        Laari::of(100_000),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    ))->toThrow(MerchantNotActiveException::class);

    expect(Transaction::query()->count())->toBe(1);
});

it('does not suspend a merchant whose payables are all inside the 15 days', function () {
    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();

    $merchant = Merchant::factory()->create();
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
    ]);

    Carbon::setTestNow($clockStart->addDays(16));

    // Another merchant with no overdue debt stays untouched too.
    $clean = Merchant::factory()->create();
    Transaction::factory()->for($clean)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => CarbonImmutable::now('UTC'),
        'due_at' => CarbonImmutable::now('UTC')->addDays(15),
    ]);

    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('suspended')
        ->and($clean->refresh()->status)->toBe('active')
        ->and(MerchantNotice::query()->where('type', 'suspended')->count())->toBe(1);
});

it('drops the public read model as it suspends, so the storefront stops advertising the store', function () {
    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();
    Carbon::setTestNow($clockStart->addDays(16));

    $merchant = Merchant::factory()->create([
        'name' => 'Clock Store',
        'slug' => 'clock-store',
        'category' => 'grocery',
        'channel' => 'in_store',
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => $clockStart->subYear(),
        'effective_to' => null,
    ]);
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
    ]);

    // A visitor warms both cached keys, exactly as a real read would.
    $data = $this->getJson('/api/discover')->assertOk()->json('data');
    expect(collect($data['in_store'])->pluck('slug'))->toContain('clock-store');
    expect(collect($data['categories'])->firstWhere('slug', 'grocery')['merchant_count'])->toBe(1);
    $this->getJson('/api/discover/merchants/clock-store')->assertOk();

    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    // Without the invalidation the shelves kept the store — and the rail
    // kept counting it — for up to 60 seconds, while the UNCACHED directory
    // grid on the same screen already answered that it is gone, and the
    // store page kept quoting 2.00% the till now refuses (§7).
    $data = $this->getJson('/api/discover')->assertOk()->json('data');
    expect($data['in_store'])->toBe([]);
    expect($data['recently_added'])->toBe([]);
    expect($data['categories'])->toBe([]);

    expect($this->getJson('/api/discover/merchants')->assertOk()->json('meta.total'))->toBe(0);
    $this->getJson('/api/discover/merchants/clock-store')->assertNotFound();
});
