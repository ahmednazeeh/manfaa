<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->user = MerchantUser::factory()->for($this->merchant)->create();
});

it('rejects guests', function () {
    $this->getJson('/api/merchant/transactions')->assertUnauthorized();
});

it('lists only the authenticated merchant\'s transactions, newest first', function () {
    $older = Transaction::factory()->for($this->merchant)->create([
        'occurred_at' => now()->subDays(2),
    ]);
    $newer = Transaction::factory()->for($this->merchant)->create([
        'occurred_at' => now()->subDay(),
    ]);

    // Another merchant's transaction never appears.
    Transaction::factory()->create();

    $this->actingAs($this->user, 'merchant')
        ->getJson('/api/merchant/transactions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('meta.total', 2);
});

it('filters by state and rejects unknown states', function () {
    Transaction::factory()->for($this->merchant)->create(['state' => 'tracked']);
    $payable = Transaction::factory()->for($this->merchant)->create(['state' => 'payable_unfunded']);

    $this->actingAs($this->user, 'merchant')
        ->getJson('/api/merchant/transactions?state=payable_unfunded')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $payable->id)
        ->assertJsonPath('data.0.state', 'payable_unfunded');

    $this->actingAs($this->user, 'merchant')
        ->getJson('/api/merchant/transactions?state=nonsense')
        ->assertUnprocessable();
});

it('exposes stored integer laari amounts, never recomputed values', function () {
    $transaction = Transaction::factory()->for($this->merchant)->create([
        'eligible_laari' => 125000,
        'cashback_laari' => 2500,
        'fee_laari' => 938,
        'fee_gst_laari' => 0,
    ]);

    $this->actingAs($this->user, 'merchant')
        ->getJson('/api/merchant/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.id', $transaction->id)
        ->assertJsonPath('data.0.eligible_laari', 125000)
        ->assertJsonPath('data.0.cashback_laari', 2500)
        ->assertJsonPath('data.0.fee_laari', 938);
});
