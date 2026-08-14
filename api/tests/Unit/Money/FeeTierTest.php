<?php

declare(strict_types=1);

use App\Domain\Money\FeeTier;
use App\Domain\Money\Rate;

it('resolves the fee tier at every boundary', function (int $cashbackBp, int $feeBp) {
    expect(FeeTier::feeBpFor($cashbackBp))->toBe($feeBp);
})->with([
    [50, 25],
    [99, 25],
    [100, 50],
    [199, 50],
    [200, 75],
    [499, 75],
    [500, 100],
    [1000, 100],
]);

it('throws outside the 50-1000 bp tier table', function (int $cashbackBp) {
    FeeTier::feeBpFor($cashbackBp);
})->throws(OutOfRangeException::class)->with([49, 1001, 0, -50]);

it('resolves a fee Rate from a cashback Rate', function () {
    expect(FeeTier::feeFor(Rate::cashback(200))->basisPoints())->toBe(75)
        ->and(FeeTier::feeFor(Rate::cashback(1000))->basisPoints())->toBe(100);
});
