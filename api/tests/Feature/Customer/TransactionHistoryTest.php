<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    $this->customer = Customer::factory()->create();
    $this->merchant = Merchant::factory()->create(['name' => 'Store A']);
});

function historyTx(Customer $customer, Merchant $merchant, string $state, array $extra = []): Transaction
{
    return Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'state' => $state,
        ...$extra,
    ]);
}

it('maps every internal state to the §6 customer-facing status with a reason key', function () {
    $expectations = [
        ['tracked', [], 'pending', 'validation_window'],
        ['awaiting_validation', [], 'pending', 'validation_window'],
        ['payable_unfunded', [], 'pending', 'merchant_settlement_window'],
        ['on_hold', [], 'pending', 'under_review'],
        ['confirmed', [], 'confirmed', null],
        ['paid', [], 'paid', null],
        ['reversed', ['reason_code' => 'customer_refund'], 'reversed', 'customer_refund'],
        ['written_off', [], 'unpaid', 'merchant_not_settled'],
    ];

    $byId = [];

    foreach ($expectations as [$state, $extra]) {
        $byId[historyTx($this->customer, $this->merchant, $state, $extra)->id] = null;
    }

    $items = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/transactions?per_page=50')
        ->assertOk()
        ->json('data');

    expect($items)->toHaveCount(count($expectations));

    $itemsById = collect($items)->keyBy('id');

    foreach ($expectations as $i => [$state, $extra, $status, $reason]) {
        $id = array_keys($byId)[$i];
        $item = $itemsById[$id];

        expect($item['status'])->toBe($status, "state {$state}");
        expect($item['status_reason'])->toBe($reason, "state {$state}");
        expect($item['merchant']['name'])->toBe('Store A');
    }
});

it('never exposes internal state or merchant commercial terms', function () {
    historyTx($this->customer, $this->merchant, 'payable_unfunded');

    $item = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/transactions')
        ->assertOk()
        ->json('data.0');

    expect($item)->toHaveKeys(['id', 'merchant', 'invoice_no', 'currency', 'eligible_laari', 'cashback_laari', 'status', 'status_reason', 'occurred_at']);
    expect($item)->not->toHaveKey('state');
    expect($item)->not->toHaveKey('fee_laari');
    expect($item)->not->toHaveKey('fee_bp');
    expect($item)->not->toHaveKey('rate_bp');
    expect($item['merchant'])->not->toHaveKey('id');
});

it('shows only the authenticated customer’s transactions, newest first, paginated', function () {
    $older = historyTx($this->customer, $this->merchant, 'confirmed', ['occurred_at' => now()->subDays(3)]);
    $newer = historyTx($this->customer, $this->merchant, 'confirmed', ['occurred_at' => now()->subDay()]);

    historyTx(Customer::factory()->create(), $this->merchant, 'confirmed');

    $response = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/transactions?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('meta.total', 2);

    $this->getJson('/api/customer/transactions?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.id', $older->id);
});

it('requires customer auth', function () {
    $this->getJson('/api/customer/transactions')->assertUnauthorized();
});
