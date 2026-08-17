<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * R3 — the mobile payout history. The rules are the WEB controller's,
 * verbatim: pending hidden, failed shown, own rows only.
 */
function payoutAuth(Customer $customer): array
{
    $token = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Phone')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Named to avoid Pest's global-function namespace: PayoutHistoryTest already
 * owns `payoutFor`. Fixture shape mirrors that file — these columns are
 * NOT NULL and the idempotency key is unique, minted from the real sequence.
 */
function mobilePayoutFor(Customer $customer, string $state, int $amount = 25000): PayoutItem
{
    $batch = PayoutBatch::query()->create([
        'reference' => 'PB-M-'.uniqid(),
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'cutoff_at' => now(),
        'state' => 'completed',
        'total_laari' => $amount,
        'currency' => 'MVR',
        'customer_count' => 1,
    ]);

    return PayoutItem::query()->create([
        'batch_id' => $batch->getKey(),
        'customer_id' => $customer->getKey(),
        'amount_laari' => $amount,
        'currency' => 'MVR',
        'idempotency_key' => sprintf(
            'MNF%06d',
            (int) DB::selectOne("select nextval('payout_items_idempotency_key_seq') as value")->value,
        ),
        'customer_name' => $customer->name,
        'customer_phone' => $customer->phone,
        'bank' => 'BML',
        'account' => '7730000012894821',
        'account_name' => $customer->name,
        'state' => $state,
    ]);
}

it('lists paid and failed payouts, newest first, in the mobile page shape', function () {
    $customer = Customer::factory()->create();
    mobilePayoutFor($customer, 'paid', 10000);
    mobilePayoutFor($customer, 'failed', 5000);

    $response = $this->withHeaders(payoutAuth($customer))
        ->getJson('/api/mobile/v1/customer/payouts')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    // Newest first — the failed one was created second.
    expect($response->json('data.0.status'))->toBe('failed');
    expect($response->json('data.0.amount_laari'))->toBe(5000);
    expect($response->json('page.has_more'))->toBeFalse();
});

it('hides pending items — a promise nobody has committed to', function () {
    $customer = Customer::factory()->create();
    mobilePayoutFor($customer, 'pending');

    $this->withHeaders(payoutAuth($customer))
        ->getJson('/api/mobile/v1/customer/payouts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('never shows another customer\'s payout, in list or detail', function () {
    $mine = Customer::factory()->create();
    $theirs = Customer::factory()->create();
    $theirPayout = mobilePayoutFor($theirs, 'paid');

    $response = $this->withHeaders(payoutAuth($mine))
        ->getJson('/api/mobile/v1/customer/payouts/'.$theirPayout->getKey())
        ->assertStatus(404);

    // Indistinguishable from a payout that does not exist — enveloped.
    expect($response->json('error.code'))->toBe('not_found');
});

it('shows what one payout covered', function () {
    $customer = Customer::factory()->create();
    $payout = mobilePayoutFor($customer, 'paid', 42000);

    $response = $this->withHeaders(payoutAuth($customer))
        ->getJson('/api/mobile/v1/customer/payouts/'.$payout->getKey())
        ->assertOk();

    expect($response->json('data.amount_laari'))->toBe(42000);
    expect($response->json('data.reference'))->not->toBeNull();
});

it('answers a malformed payouts cursor with 422, not 500', function () {
    $customer = Customer::factory()->create();

    // Decodes to valid JSON with the marker key but none of the ordering
    // params — reaches addCursorConditions and used to throw uncaught.
    $cursor = base64_encode(json_encode(['_pointsToNextItems' => true]));

    $response = $this->withHeaders(payoutAuth($customer))
        ->getJson('/api/mobile/v1/customer/payouts?cursor='.$cursor)
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed');
});
