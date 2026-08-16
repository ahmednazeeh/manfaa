<?php

declare(strict_types=1);

use App\Models\MerchantUser;
use App\Models\Settlement;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    $this->fixture = SettlementFixture::payableBatch();
    $this->actingAs($this->fixture->user, 'merchant');
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
 * PLAN §1: "no settlement exists without a payment receipt". The whole point
 * of the decision is that the admin queue never contains an unverifiable
 * claim, and that the merchant never lands on an awaiting_payment dead end.
 * These tests are the load-bearing ones: they assert the ABSENCE of every
 * route that could produce a receiptless settlement.
 */

it('has removed the old draft-then-submit merchant endpoints', function () {
    // The old pair: create a draft, then submit it separately. Both are gone
    // — not merely refused, but absent from the route table.
    $this->postJson('/api/merchant/settlements/1/submit')->assertNotFound();
    $this->postJson('/api/merchant/settlements/1/wallet-settle')->assertNotFound();

    // And nothing anywhere in the API answers them for any id.
    $this->postJson('/api/merchant/settlements/999999/submit')->assertNotFound();

    expect(Settlement::query()->count())->toBe(0);
});

it('refuses a settlement submission with no slip at all', function () {
    $this->postJson('/api/merchant/settlements', [
        'settle_all' => true,
        'amount' => 11825,
        'bank_ref' => 'BML-NO-SLIP',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slip']);

    expect(Settlement::query()->count())->toBe(0);
});

it('refuses a settlement submission with no bank reference and no amount', function () {
    $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'slip' => Slips::jpeg(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount', 'bank_ref']);

    expect(Settlement::query()->count())->toBe(0);
});

it('leaves nothing behind when the receipt is refused', function () {
    // A spoofed slip fails INSIDE the one transaction that would otherwise
    // have built the batch: no settlement, no lines claimed, no stored file.
    $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => 11825,
        'bank_ref' => 'BML-SPOOF-1',
        'slip' => Slips::spoofedPng(),
    ])->assertUnprocessable();

    expect(Settlement::query()->count())->toBe(0)
        ->and(Storage::disk('slips')->allFiles())->toBe([]);

    // The transactions were never claimed, so they are still fully payable.
    $this->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4)
        ->assertJsonPath('data.total.payable_laari', 11825);
});

it('never lets a merchant reach awaiting_payment', function () {
    $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => 11825,
        'bank_ref' => 'BML-STATE-1',
        'slip' => Slips::jpeg(),
    ])->assertCreated();

    // The batch skipped awaiting_payment entirely: the payment was recorded
    // in the same database transaction that created it.
    expect(Settlement::query()->sole()->state->value)->toBe('payment_review');

    $this->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonPath('data.0.state', 'payment_review')
        ->assertJsonPath('data.0.merchant_status.code', 'verifying')
        ->assertJsonPath('data.0.merchant_status.message', 'Manfaa is verifying your transfer.');
});

it('gates the receipt-first endpoint on settlements.create and an approved store', function () {
    $staff = MerchantUser::factory()->for($this->fixture->merchant)->staff()->create();

    $this->actingAs($staff, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 11825,
            'bank_ref' => 'BML-ROLE-1',
            'slip' => Slips::jpeg(),
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_required')
        ->assertJsonPath('permission', 'settlements.create');

    $this->fixture->merchant->update(['status' => 'pending_review']);

    $this->actingAs($this->fixture->user, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 11825,
            'bank_ref' => 'BML-APPROVED-1',
            'slip' => Slips::jpeg(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_approved');

    expect(Settlement::query()->count())->toBe(0);
});
