<?php

declare(strict_types=1);

use App\Domain\Money\Assert;
use App\Domain\Money\Laari;

it('parses MVR strings into laari with integer math', function (string $mvr, int $laari) {
    expect(Laari::fromMvrString($mvr)->value())->toBe($laari);
})->with([
    ['4300', 430000],
    ['4300.2', 430020],
    ['4300.25', 430025],
    ['0', 0],
    ['0.50', 50],
    ['-4300.25', -430025],
    ['-0.5', -50],
]);

it('rejects malformed MVR strings', function (string $mvr) {
    Laari::fromMvrString($mvr);
})->throws(InvalidArgumentException::class)->with([
    '4300.255',
    'abc',
    '-',
    '',
    '.',
    '4300.',
    '.25',
    '4,300',
    '4300.2.5',
    '+4300',
    '43 00',
    '4300,25',
]);

it('formats laari as grouped MVR without floats', function (int $laari, string $formatted) {
    expect(Laari::of($laari)->formatMvr())->toBe($formatted);
})->with([
    [430025, '4,300.25'],
    [50, '0.50'],
    [-50, '-0.50'],
    [0, '0.00'],
    [5, '0.05'],
    [100, '1.00'],
    [1182500, '11,825.00'],
    [123456789, '1,234,567.89'],
    [-123456789, '-1,234,567.89'],
    [100000000000, '1,000,000,000.00'],
]);

it('round-trips fromMvrString through formatMvr', function () {
    expect(Laari::fromMvrString('4300.25')->formatMvr())->toBe('4,300.25')
        ->and(Laari::fromMvrString('-0.50')->formatMvr())->toBe('-0.50');
});

it('adds and subtracts, allowing negative results for adjustments', function () {
    $a = Laari::of(1000);
    $b = Laari::of(2750);

    expect($a->add($b)->value())->toBe(3750)
        ->and($a->subtract($b)->value())->toBe(-1750)
        ->and($a->subtract($b)->isNegative())->toBeTrue()
        ->and($b->subtract($a)->isNegative())->toBeFalse()
        ->and(Laari::of(0)->isNegative())->toBeFalse();
});

it('compares by value', function () {
    expect(Laari::of(2750)->equals(Laari::of(2750)))->toBeTrue()
        ->and(Laari::of(2750)->equals(Laari::of(-2750)))->toBeFalse();
});

it('accepts values at the sanity bound', function () {
    expect(Assert::laariRange(9_000_000_000_000))->toBe(9_000_000_000_000)
        ->and(Assert::laariRange(-9_000_000_000_000))->toBe(-9_000_000_000_000)
        ->and(Laari::of(9_000_000_000_000)->value())->toBe(9_000_000_000_000);
});

it('rejects values beyond the sanity bound', function (int $value) {
    Assert::laariRange($value);
})->throws(InvalidArgumentException::class)->with([
    9_000_000_000_001,
    -9_000_000_000_001,
]);

it('rejects out-of-bound construction and arithmetic', function () {
    expect(fn () => Laari::of(9_000_000_000_001))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Laari::of(9_000_000_000_000)->add(Laari::of(1)))->toThrow(InvalidArgumentException::class);
});
