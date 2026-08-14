<?php

declare(strict_types=1);

use App\Domain\Money\Rate;

it('accepts cashback rates across the 50-2000 bp range', function (int $bp) {
    expect(Rate::cashback($bp)->basisPoints())->toBe($bp);
})->with([50, 99, 100, 200, 499, 500, 1000, 1500, 1999, 2000]);

it('rejects cashback rates outside 50-2000 bp', function (int $bp) {
    Rate::cashback($bp);
})->throws(OutOfRangeException::class)->with([49, 2001, 0, -200]);

// The exact structural boundary: 1999 and 2000 are legal, 2001 is not.
it('holds the 2000 bp structural boundary exactly', function () {
    expect(Rate::cashback(1999)->basisPoints())->toBe(1999)
        ->and(Rate::cashback(2000)->basisPoints())->toBe(2000)
        ->and(fn () => Rate::cashback(2001))->toThrow(OutOfRangeException::class);
});

// Historically only the four §4 tier values; admin-managed fee tier
// schedules make any positive integer fee up to 2000 bp legal.
it('accepts fee rates across the 1-2000 bp range', function (int $bp) {
    expect(Rate::fee($bp)->basisPoints())->toBe($bp);
})->with([1, 24, 25, 26, 50, 75, 99, 100, 101, 200, 437, 1000, 2000]);

it('rejects fee rates outside 1-2000 bp', function (int $bp) {
    Rate::fee($bp);
})->throws(InvalidArgumentException::class)->with([0, -25, 2001, 4000]);

it('compares by basis points', function () {
    expect(Rate::cashback(200)->equals(Rate::cashback(200)))->toBeTrue()
        ->and(Rate::cashback(200)->equals(Rate::cashback(201)))->toBeFalse();
});
