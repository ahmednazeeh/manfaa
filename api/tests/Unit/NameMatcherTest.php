<?php

declare(strict_types=1);

use App\Domain\Transfers\NameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Matching a payer's name to a customer's.
 *
 * The two pairs the owner named are the specification, and they are both
 * here verbatim: "Ahmed Nazeeh" must match "Ahmd Nazeeh", and
 * "Ahmed Nazeeh Adam" must match "Ahmd N Adam".
 *
 * The threshold was calibrated against real Maldivian name pairs rather than
 * chosen: true positives scored 0.375–1.000 on trigram similarity and false
 * positives 0.000–0.167, so 0.30 sits in the gap.
 */

it('matches a misspelt first name', function (): void {
    // The owner's first example.
    expect(app(NameMatcher::class)->score('Ahmed Nazeeh', 'AHMD NAZEEH'))
        ->toBeGreaterThanOrEqual(60);
});

it('matches an initial standing in for a middle name', function (): void {
    // The owner's second example: Nazeeh → N.
    expect(app(NameMatcher::class)->score('Ahmed Nazeeh Adam', 'AHMD N ADAM'))
        ->toBeGreaterThanOrEqual(60);
});

it('sees through a bank prefix', function (): void {
    // MIB records an incoming IPS transfer as "BML - AHMD NAZEEH". The
    // routing label is not part of the payer's name.
    expect(app(NameMatcher::class)->score('Ahmed Nazeeh', 'BML - AHMD NAZEEH'))
        ->toBeGreaterThanOrEqual(60);
});

it('ignores case, honorifics and punctuation', function (): void {
    expect(app(NameMatcher::class)->score('ahmed nazeeh', 'MR. AHMED NAZEEH'))
        ->toBeGreaterThanOrEqual(60);
});

it('refuses a different person', function (): void {
    $matcher = app(NameMatcher::class);

    // The case that matters most: two real customers, both paying, neither
    // of whom may be credited with the other's money.
    expect($matcher->score('Ahmed Nazeeh', 'MARIYAM SHIFA'))->toBeNull();
    expect($matcher->score('Ahmed Nazeeh', 'IBRAHIM RASHEED'))->toBeNull();
});

it('refuses a name that is only half there', function (): void {
    // Every token of the customer's name has to find a home. A payer called
    // simply "AHMED" is not proof that Ahmed Nazeeh Adam paid.
    expect(app(NameMatcher::class)->score('Ahmed Nazeeh Adam', 'AHMED'))->toBeNull();
});

it('refuses an initial that matches the wrong letter', function (): void {
    expect(app(NameMatcher::class)->score('Ahmed Nazeeh Adam', 'AHMD K ADAM'))->toBeNull();
});

it('does not let one bank token satisfy two customer tokens', function (): void {
    // A matched token is consumed. Without that, a payer named "AHMED"
    // would satisfy both halves of "Ahmed Ahmed".
    expect(app(NameMatcher::class)->score('Ahmed Ahmed', 'AHMED'))->toBeNull();
});

it('refuses an empty name on either side', function (): void {
    $matcher = app(NameMatcher::class);

    expect($matcher->score('', 'AHMED NAZEEH'))->toBeNull();
    expect($matcher->score('Ahmed Nazeeh', ''))->toBeNull();
    expect($matcher->score('Ahmed Nazeeh', '-'))->toBeNull();
});

it('scores an exact match highest', function (): void {
    expect(app(NameMatcher::class)->score('Ahmed Nazeeh', 'AHMED NAZEEH'))->toBe(100);
});
