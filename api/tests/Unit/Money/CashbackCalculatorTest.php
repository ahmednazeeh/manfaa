<?php

declare(strict_types=1);

use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;

it('reproduces the §4 fixture line by line and sums the batch from the lines', function () {
    $calculator = new CashbackCalculator;
    $rate = Rate::cashback(200);

    $fixture = [
        // invoice        eligible  cashback  fee    due
        ['INV-1001', 100000, 2000, 750, 2750],
        ['INV-1002', 50000, 1000, 375, 1375],
        ['INV-1003', 200000, 4000, 1500, 5500],
        ['INV-1004', 80000, 1600, 600, 2200],
    ];

    $eligibleSum = Laari::of(0);
    $cashbackSum = Laari::of(0);
    $feeSum = Laari::of(0);
    $dueSum = Laari::of(0);

    foreach ($fixture as [$invoice, $eligible, $cashback, $fee, $due]) {
        $result = $calculator->calculate(Laari::of($eligible), $rate);

        expect($result->cashbackLaari)->toBe($cashback, "cashback for {$invoice}")
            ->and($result->feeLaari)->toBe($fee, "fee for {$invoice}")
            ->and($result->due()->value())->toBe($due, "due for {$invoice}")
            ->and($result->rateBp)->toBe(200)
            ->and($result->feeBp)->toBe(75);

        // Batch totals accumulate rounded line values — never recomputed on aggregates.
        $eligibleSum = $eligibleSum->add(Laari::of($eligible));
        $cashbackSum = $cashbackSum->add($result->cashback());
        $feeSum = $feeSum->add($result->fee());
        $dueSum = $dueSum->add($result->due());
    }

    expect($eligibleSum->value())->toBe(430000)
        ->and($cashbackSum->value())->toBe(8600)
        ->and($feeSum->value())->toBe(3225)
        ->and($dueSum->value())->toBe(11825)
        ->and($dueSum->equals($cashbackSum->add($feeSum)))->toBeTrue();

    expect($eligibleSum->formatMvr())->toBe('4,300.00')
        ->and($cashbackSum->formatMvr())->toBe('86.00')
        ->and($feeSum->formatMvr())->toBe('32.25')
        ->and($dueSum->formatMvr())->toBe('118.25');
});

it('rounds 33300 laari at 75 bp half-up to 250', function () {
    // 33300 * 75 = 2,497,500; + 5000 = 2,502,500; intdiv 10000 = 250.
    // (MVR 333 at 0.75% is MVR 2.4975 -> rounds half-up to MVR 2.50.)
    $result = (new CashbackCalculator)->calculate(Laari::of(33300), Rate::cashback(75));

    expect($result->feeBp)->toBe(25)
        ->and($result->cashbackLaari)->toBe(intdiv(33300 * 75 + 5000, 10000))
        ->and($result->cashbackLaari)->toBe(250);
});

it('rounds products ending in exactly .5 laari upward', function (int $eligible, int $rateBp, int $expectedCashback) {
    // Each product eligible * rate_bp ends in exactly 5000, i.e. x.5 laari.
    expect(($eligible * $rateBp) % 10000)->toBe(5000);

    $result = (new CashbackCalculator)->calculate(Laari::of($eligible), Rate::cashback($rateBp));

    expect($result->cashbackLaari)->toBe($expectedCashback);
})->with([
    [25, 200, 1],      // 0.5 -> 1
    [75, 200, 2],      // 1.5 -> 2
    [175, 200, 4],     // 3.5 -> 4
    [50, 100, 1],      // 0.5 -> 1
    [150, 500, 8],     // 7.5 -> 8
    [1250, 500, 63],   // 62.5 -> 63
]);

it('rounds the fee half-up at exactly .5 laari', function () {
    // Eligible 100 laari at 100 bp -> fee tier 50 bp: 100 * 50 = 5000 -> 0.5 -> 1.
    $result = (new CashbackCalculator)->calculate(Laari::of(100), Rate::cashback(100));

    expect($result->feeBp)->toBe(50)
        ->and($result->feeLaari)->toBe(1)
        ->and($result->cashbackLaari)->toBe(1); // 100 * 100 = 10000 -> exactly 1.0
});

it('rounds just below and just above the half boundary correctly', function () {
    $calculator = new CashbackCalculator;

    // 24 * 200 = 4800 -> 0.48 rounds down; 26 * 200 = 5200 -> 0.52 rounds up.
    expect($calculator->calculate(Laari::of(24), Rate::cashback(200))->cashbackLaari)->toBe(0)
        ->and($calculator->calculate(Laari::of(26), Rate::cashback(200))->cashbackLaari)->toBe(1);
});

it('clips cashback to the cap and derives the fee from the granted reward', function () {
    $calculator = new CashbackCalculator;

    // Normal: 100000 @ 200bp -> 2000 cashback, 750 fee. Cap clips to 1000.
    $result = $calculator->calculateCapped(Laari::of(100000), Rate::cashback(200), Laari::of(1000));

    expect($result->cashbackLaari)->toBe(1000)
        ->and($result->feeLaari)->toBe(intdiv(1000 * 75 + intdiv(200, 2), 200))
        ->and($result->feeLaari)->toBe(375)
        ->and($result->rateBp)->toBe(200)
        ->and($result->feeBp)->toBe(75);
});

it('leaves the normal result untouched when the cap does not clip', function () {
    $calculator = new CashbackCalculator;
    $eligible = Laari::of(100000);
    $rate = Rate::cashback(200);

    $capped = $calculator->calculateCapped($eligible, $rate, Laari::of(2000));
    $roomy = $calculator->calculateCapped($eligible, $rate, Laari::of(999999));
    $normal = $calculator->calculate($eligible, $rate);

    expect($capped->cashbackLaari)->toBe($normal->cashbackLaari)
        ->and($capped->feeLaari)->toBe($normal->feeLaari)
        ->and($roomy->cashbackLaari)->toBe(2000)
        ->and($roomy->feeLaari)->toBe(750);
});

it('caps cashback to zero with zero fee when no cap remains', function () {
    $result = (new CashbackCalculator)->calculateCapped(Laari::of(100000), Rate::cashback(200), Laari::of(0));

    expect($result->cashbackLaari)->toBe(0)
        ->and($result->feeLaari)->toBe(0);
});

it('preserves the fee-to-cashback ratio under capping at 200bp/75bp', function () {
    $calculator = new CashbackCalculator;
    $rate = Rate::cashback(200);
    // Eligible large enough that every cap below always clips: normal cashback is 20000.
    $eligible = Laari::of(1_000_000);

    foreach (range(1, 2000) as $cap) {
        $result = $calculator->calculateCapped($eligible, $rate, Laari::of($cap));

        // Expected in integer math: round-half-up of cashback * 0.375
        // = intdiv(cashback * 375 + 500, 1000).
        $expectedFee = intdiv($cap * 375 + 500, 1000);

        expect($result->cashbackLaari)->toBe($cap)
            ->and($result->feeLaari)->toBe($expectedFee, "fee ratio broken at cashback {$cap}");
    }
});
