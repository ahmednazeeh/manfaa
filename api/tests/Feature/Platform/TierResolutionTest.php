<?php

declare(strict_types=1);

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TermsResolver;
use App\Domain\Money\Laari;
use App\Models\Customer;
use App\Models\FeeTierSchedule;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Appends a schedule row directly (the admin endpoint only accepts future
 * dates; resolution tests need historical boundaries).
 */
function scheduleEffectiveAt(CarbonImmutable $effectiveFrom, array $tiers): FeeTierSchedule
{
    return FeeTierSchedule::query()->create([
        'effective_from' => $effectiveFrom,
        'tiers' => $tiers,
        'created_by' => null,
        'created_at' => CarbonImmutable::now('UTC'),
    ]);
}

it('resolves terms across a schedule boundary: T-1s prices under the old schedule, T+1s under the new', function () {
    $boundary = CarbonImmutable::parse('2026-08-12T10:00:00+05:00')->utc();

    scheduleEffectiveAt($boundary, [
        ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 30],
        ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 60],
        ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 100],
        ['from_bp' => 500, 'to_bp' => 1000, 'fee_bp' => 150],
    ]);

    $resolver = app(TermsResolver::class);
    $eligible = Laari::of(100000);

    // One second before the boundary: the seeded §4 default (200bp -> 75bp).
    [$before] = $resolver->resolve(1, null, $eligible, 200, 1, $boundary->subSecond());
    expect($before->rateBp)->toBe(200)
        ->and($before->feeBp)->toBe(75)
        ->and($before->cashbackLaari)->toBe(2000)
        ->and($before->feeLaari)->toBe(750);

    // One second after: the new schedule (200bp -> 100bp). Cashback is
    // untouched — only the platform fee follows the schedule.
    [$after] = $resolver->resolve(1, null, $eligible, 200, 1, $boundary->addSecond());
    expect($after->rateBp)->toBe(200)
        ->and($after->feeBp)->toBe(100)
        ->and($after->cashbackLaari)->toBe(2000)
        ->and($after->feeLaari)->toBe(1000);

    // At the boundary instant itself the new schedule applies (<=).
    [$at] = $resolver->resolve(1, null, $eligible, 200, 1, $boundary);
    expect($at->feeBp)->toBe(100);
});

it('keeps fee_bp frozen on existing rows when a later schedule lands', function () {
    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create(['validation_window_days' => 3, 'min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $user = MerchantUser::factory()->for($merchant)->owner()->create();
    $customer = Customer::factory()->create(['customer_code' => '482917']);

    $transaction = app(ManualCreditService::class)->credit(
        $merchant,
        $user,
        $customer->customer_code,
        'INV-FROZEN-1',
        Laari::of(100000),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    );

    expect($transaction->fee_bp)->toBe(75)->and($transaction->fee_laari)->toBe(750);

    // A schedule effective BEFORE the row's occurred_at lands afterwards.
    // The stored row is never recomputed — §4: reversals and history reverse
    // stored integers.
    scheduleEffectiveAt(CarbonImmutable::now('UTC')->subDay(), [
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 20],
    ]);

    $transaction->refresh();

    expect($transaction->fee_bp)->toBe(75)
        ->and($transaction->fee_laari)->toBe(750)
        ->and($transaction->cashback_laari)->toBe(2000);

    // New credits DO price under the newly landed schedule.
    $fresh = app(ManualCreditService::class)->credit(
        $merchant,
        $user,
        $customer->customer_code,
        'INV-FROZEN-2',
        Laari::of(100000),
        null,
        CarbonImmutable::now('UTC')->subMinutes(5),
    );

    expect($fresh->fee_bp)->toBe(20)->and($fresh->fee_laari)->toBe(200);
});

it('reproduces the §4 fixture exactly under the seeded default schedule, end to end', function () {
    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create(['validation_window_days' => 3, 'min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $user = MerchantUser::factory()->for($merchant)->owner()->create();
    $customer = Customer::factory()->create(['customer_code' => '482917']);

    $fixture = [
        // invoice     eligible  cashback  fee    due
        ['INV-1001', 100000, 2000, 750, 2750],
        ['INV-1002', 50000, 1000, 375, 1375],
        ['INV-1003', 200000, 4000, 1500, 5500],
        ['INV-1004', 80000, 1600, 600, 2200],
    ];

    $cashbackSum = 0;
    $feeSum = 0;

    foreach ($fixture as [$invoice, $eligible, $cashback, $fee, $due]) {
        $transaction = app(ManualCreditService::class)->credit(
            $merchant,
            $user,
            $customer->customer_code,
            $invoice,
            Laari::of($eligible),
            null,
            CarbonImmutable::now('UTC')->subHour(),
        );

        expect($transaction->rate_bp)->toBe(200, "rate for {$invoice}")
            ->and($transaction->fee_bp)->toBe(75, "fee_bp for {$invoice}")
            ->and($transaction->cashback_laari)->toBe($cashback, "cashback for {$invoice}")
            ->and($transaction->fee_laari)->toBe($fee, "fee for {$invoice}")
            ->and($transaction->cashback_laari + $transaction->fee_laari)->toBe($due, "due for {$invoice}");

        $cashbackSum += $transaction->cashback_laari;
        $feeSum += $transaction->fee_laari;
    }

    expect($cashbackSum)->toBe(8600)
        ->and($feeSum)->toBe(3225)
        ->and($cashbackSum + $feeSum)->toBe(11825);
});
