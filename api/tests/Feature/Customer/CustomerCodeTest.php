<?php

declare(strict_types=1);

use App\Domain\Cashback\CustomerRef;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The customer code is the till's identity key, and CustomerRef accepts
 * EITHER a 6-digit code or a 7-digit local mobile. A mobile with one digit
 * dropped is therefore shape-identical to a code — so no NEW code may live
 * in the 7xxxxx / 9xxxxx ranges, or a cashier's fumble credits a stranger.
 */
it('never issues a code that a one-digit-short Maldivian mobile could hit', function () {
    $codes = [];

    for ($i = 0; $i < 300; $i++) {
        $codes[] = Customer::generateCode();
    }

    foreach ($codes as $code) {
        expect($code)->toMatch('/^[1-68]\d{5}$/');

        // The dropped-digit collision, stated the way the till sees it: a
        // truncated 7712345 / 9912345 must never parse into a live code.
        expect($code[0])->not->toBe('7')
            ->and($code[0])->not->toBe('9');
    }

    // Both halves of the space stay reachable — the exclusion must not have
    // quietly collapsed issuance into 1xxxxx–6xxxxx only.
    expect(array_filter($codes, fn (string $c): bool => $c[0] === '8'))->not->toBeEmpty();
    expect(array_filter($codes, fn (string $c): bool => $c[0] < '7'))->not->toBeEmpty();
});

it('still skips codes that are already taken', function () {
    $taken = Customer::generateCode();
    Customer::factory()->create(['customer_code' => $taken]);

    for ($i = 0; $i < 50; $i++) {
        expect(Customer::generateCode())->not->toBe($taken);
    }
});

it('keeps codes issued before the rule valid — they are printed and carried as QR', function () {
    $legacy = Customer::factory()->create([
        'customer_code' => '771234',
        'phone' => '+9609990001',
    ]);

    // The till still resolves the grandfathered code, and still reads it as
    // a code rather than a phone.
    $ref = CustomerRef::parse('771234');

    expect($ref)->not->toBeNull()
        ->and($ref->isPhone)->toBeFalse()
        ->and($ref->resolve()?->id)->toBe($legacy->id);
});

it('resolves a truncated mobile to nobody once the reserved ranges are unissuable', function () {
    // A customer whose mobile is +9607712345. The cashier drops the last
    // digit and types 771234, which CustomerRef reads as a CODE.
    Customer::factory()->create(['phone' => '+9607712345', 'customer_code' => Customer::generateCode()]);

    // Fresh accounts, none of which can hold 771234 any more.
    for ($i = 0; $i < 50; $i++) {
        Customer::factory()->create(['customer_code' => Customer::generateCode()]);
    }

    expect(CustomerRef::parse('771234')?->resolve())->toBeNull();
});
