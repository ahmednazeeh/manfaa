<?php

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function payoutFor(Customer $customer, string $state, int $amount, array $batch = []): PayoutItem
{
    $payoutBatch = PayoutBatch::query()->create(array_merge([
        'reference' => 'MANFAA-2026-07-'.uniqid(),
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'cutoff_at' => CarbonImmutable::parse('2026-07-24T18:59:59Z'),
        'state' => 'completed',
        'total_laari' => 24_000,
        'currency' => 'MVR',
        'customer_count' => 1,
    ], $batch));

    return PayoutItem::query()->create([
        'batch_id' => $payoutBatch->id,
        'customer_id' => $customer->id,
        'amount_laari' => $amount,
        'currency' => 'MVR',
        'bank' => 'BML',
        'account' => '7730000012894821',
        'account_name' => 'Aisha Mohamed',
        'state' => $state,
    ]);
}

it('lists the payouts that reached the bank, masking the account', function () {
    $customer = Customer::factory()->create();
    $paid = payoutFor($customer, 'paid', 24_000);

    $this->actingAs($customer, 'customer')
        ->getJson('/api/customer/payouts')
        ->assertOk()
        ->assertJsonPath('data.0.id', $paid->id)
        ->assertJsonPath('data.0.amount_laari', 24_000)
        ->assertJsonPath('data.0.status', 'paid')
        ->assertJsonPath('data.0.reference', $paid->batch->reference)
        ->assertJsonPath('data.0.period_start', '2026-07-01')
        ->assertJsonPath('data.0.period_end', '2026-07-31')
        // Last four only — a payout screen is the kind of thing people
        // screenshot for support.
        ->assertJsonPath('data.0.account_masked', '•••• 4821')
        ->assertJsonMissing(['account' => '7730000012894821']);
});

/**
 * A pending item belongs to a draft batch nobody has approved. Showing it
 * would promise money on a date the platform has not committed to.
 */
it('hides pending payouts and shows failed ones', function () {
    $customer = Customer::factory()->create();
    payoutFor($customer, 'pending', 10_000);
    $failed = payoutFor($customer, 'failed', 5_000);
    $failed->update(['failure_reason' => 'account_closed']);

    $response = $this->actingAs($customer, 'customer')
        ->getJson('/api/customer/payouts')
        ->assertOk();

    $states = collect($response->json('data'))->pluck('status')->all();

    expect($states)->toBe(['failed'])
        ->and($response->json('data.0.failure_reason'))->toBe('account_closed');
});

it('never shows another customer their neighbour payouts', function () {
    $mine = Customer::factory()->create();
    $theirs = Customer::factory()->create();
    $neighbour = payoutFor($theirs, 'paid', 99_000);

    $this->actingAs($mine, 'customer')
        ->getJson('/api/customer/payouts')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Indistinguishable from a payout that does not exist.
    $this->actingAs($mine, 'customer')
        ->getJson("/api/customer/payouts/{$neighbour->id}")
        ->assertNotFound();
});

it('opens a payout onto the purchases it paid for', function () {
    $customer = Customer::factory()->create();
    $payout = payoutFor($customer, 'paid', 2_750);

    $kaanu = Merchant::factory()->create(['name' => 'Kaanu Mart', 'name_dv' => 'ކާނު މާޓް']);
    $fahi = Merchant::factory()->create(['name' => 'Fahi Cafe', 'name_dv' => null]);

    Transaction::factory()->create([
        'customer_id' => $customer->id,
        'merchant_id' => $kaanu->id,
        'payout_item_id' => $payout->id,
        'invoice_no' => 'INV-9001',
        'eligible_laari' => 100_000,
        'cashback_laari' => 2_000,
        'occurred_at' => CarbonImmutable::parse('2026-07-04T10:00:00Z'),
    ]);
    Transaction::factory()->create([
        'customer_id' => $customer->id,
        'merchant_id' => $fahi->id,
        'payout_item_id' => $payout->id,
        'invoice_no' => 'INV-9002',
        'eligible_laari' => 37_500,
        'cashback_laari' => 750,
        'occurred_at' => CarbonImmutable::parse('2026-07-19T10:00:00Z'),
    ]);

    // A transaction of the same customer NOT in this payout must not leak in.
    Transaction::factory()->create([
        'customer_id' => $customer->id,
        'merchant_id' => $kaanu->id,
        'payout_item_id' => null,
        'invoice_no' => 'INV-9003',
        'cashback_laari' => 500,
    ]);

    $response = $this->actingAs($customer, 'customer')
        ->getJson("/api/customer/payouts/{$payout->id}")
        ->assertOk()
        ->assertJsonPath('data.amount_laari', 2_750)
        ->assertJsonCount(2, 'data.transactions')
        // Newest purchase first.
        ->assertJsonPath('data.transactions.0.invoice_no', 'INV-9002')
        ->assertJsonPath('data.transactions.0.merchant.name', 'Fahi Cafe')
        ->assertJsonPath('data.transactions.0.merchant.name_dv', null)
        ->assertJsonPath('data.transactions.1.invoice_no', 'INV-9001')
        ->assertJsonPath('data.transactions.1.merchant.name', 'Kaanu Mart')
        ->assertJsonPath('data.transactions.1.merchant.name_dv', 'ކާނު މާޓް')
        ->assertJsonPath('data.transactions.1.cashback_laari', 2_000);

    // The listed cashback adds up to the payment.
    $sum = collect($response->json('data.transactions'))->sum('cashback_laari');
    expect($sum)->toBe(2_750);
});

it('requires a session', function () {
    $this->getJson('/api/customer/payouts')->assertUnauthorized();
});
