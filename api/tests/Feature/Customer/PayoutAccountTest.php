<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    $this->customer = Customer::factory()->create();
});

it('starts with no payout account', function () {
    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/payout-account')
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', false)
        ->assertJsonPath('data.bank_name', null);
});

it('registers a payout account and reads it back', function () {
    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/payout-account', [
            'bank_name' => 'Bank of Maldives',
            'account_no' => '7701234567890',
            'account_name' => 'AISHATH MANIKE',
        ])
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', true)
        // Snapshot semantics: a change during a processing batch takes
        // effect from the NEXT batch (payout_items snapshot at build time).
        ->assertJsonPath('data.change_effective', 'next_batch');

    $this->getJson('/api/customer/payout-account')
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'Bank of Maldives')
        ->assertJsonPath('data.account_no', '7701234567890')
        ->assertJsonPath('data.account_name', 'AISHATH MANIKE');

    $this->customer->refresh();
    expect($this->customer->payout_bank)->toBe('Bank of Maldives');
    expect($this->customer->payout_account)->toBe('7701234567890');
    expect($this->customer->payout_account_name)->toBe('AISHATH MANIKE');
});

it('allows updating an existing account (effective next batch)', function () {
    $this->customer->forceFill([
        'payout_bank' => 'Bank of Maldives',
        'payout_account' => '7701234567890',
        'payout_account_name' => 'AISHATH MANIKE',
    ])->save();

    $this->actingAs($this->customer, 'customer')
        ->postJson('/api/customer/payout-account', [
            'bank_name' => 'MIB',
            'account_no' => '990001112223',
            'account_name' => 'AISHATH MANIKE',
        ])
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'MIB');
});

it('validates the payload', function () {
    $this->actingAs($this->customer, 'customer');

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'Bank of Maldives',
        'account_name' => 'AISHATH MANIKE',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_no');

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'Bank of Maldives',
        'account_no' => 'not-a-number',
        'account_name' => 'AISHATH MANIKE',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_no');

    $this->postJson('/api/customer/payout-account', [
        'account_no' => '7701234567890',
        'account_name' => 'AISHATH MANIKE',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bank_name');
});

it('requires customer auth', function () {
    $this->getJson('/api/customer/payout-account')->assertUnauthorized();
    $this->postJson('/api/customer/payout-account', [])->assertUnauthorized();
});
