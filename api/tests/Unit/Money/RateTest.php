<?php

declare(strict_types=1);

use App\Domain\Money\Rate;

it('accepts cashback rates across the 50-1000 bp range', function (int $bp) {
    expect(Rate::cashback($bp)->basisPoints())->toBe($bp);
})->with([50, 99, 100, 200, 499, 500, 1000]);

it('rejects cashback rates outside 50-1000 bp', function (int $bp) {
    Rate::cashback($bp);
})->throws(OutOfRangeException::class)->with([49, 1001, 0, -200]);

it('accepts exactly the four tier fee rates', function (int $bp) {
    expect(Rate::fee($bp)->basisPoints())->toBe($bp);
})->with([25, 50, 75, 100]);

it('rejects any other fee rate', function (int $bp) {
    Rate::fee($bp);
})->throws(InvalidArgumentException::class)->with([0, 24, 26, 99, 101, 200, -25]);

it('compares by basis points', function () {
    expect(Rate::cashback(200)->equals(Rate::cashback(200)))->toBeTrue()
        ->and(Rate::cashback(200)->equals(Rate::cashback(201)))->toBeFalse();
});
