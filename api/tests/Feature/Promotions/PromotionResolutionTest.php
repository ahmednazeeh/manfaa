<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\CreditRecorder;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    // Published 500bp promo, live now, min purchase MVR 1,000 (100000 laari).
    $this->promotion = Promotion::query()->create([
        'merchant_id' => $this->merchant->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'min_purchase_laari' => 100000,
        'status' => 'published',
        'published_at' => now()->subDays(2),
    ]);

    $this->actingAs($this->user, 'merchant');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoCreditPayload(array $overrides = []): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
        'eligible_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];
}

it('credits at the promo 500bp inside the window — fee 100bp follows the promo tier, not the standing 200/75', function () {
    $this->postJson('/api/merchant/credits', promoCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.cashback_rate_percent', '5.00')
        ->assertJsonPath('data.platform_fee_percent', '1.00')
        ->assertJsonPath('data.cashback_laari', intdiv(125000 * 500 + 9999, 10000))
        ->assertJsonPath('data.fee_laari', intdiv(125000 * 100 + 9999, 10000));

    $transaction = Transaction::query()->sole();

    expect($transaction->promotion_id)->toBe($this->promotion->id)
        ->and($transaction->cashback_laari)->toBe(6250)
        ->and($transaction->fee_laari)->toBe(1250);

    $balances = new Balances;

    expect($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(6250 + 1250)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(6250)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(1250);
});

it('falls back to the standing rate below the promo minimum purchase — never rejected', function () {
    // 50,000 laari is above the merchant minimum (5,000) but below the promo
    // minimum purchase (100,000): the sale earns normally at 200/75.
    $this->postJson('/api/merchant/credits', promoCreditPayload(['eligible_amount' => 50000]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.platform_fee_percent', '0.75')
        ->assertJsonPath('data.cashback_laari', 1000)
        ->assertJsonPath('data.fee_laari', 375);

    expect(Transaction::query()->sole()->promotion_id)->toBeNull();
});

it('uses the standing rate outside the promo window (rate frozen at occurred_at)', function () {
    $this->postJson('/api/merchant/credits', promoCreditPayload([
        'occurred_at' => now()->subDays(2)->toIso8601String(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.platform_fee_percent', '0.75');

    expect(Transaction::query()->sole()->promotion_id)->toBeNull();
});

it('ignores draft and cancelled promotions — only PUBLISHED promotions price sales', function () {
    $this->promotion->update(['status' => 'draft']);

    $this->postJson('/api/merchant/credits', promoCreditPayload())
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '2.00');

    expect(Transaction::query()->sole()->promotion_id)->toBeNull();
});

it('scopes a branch promotion to its own branch — other branches earn the standing rate', function () {
    $branchA = MerchantBranch::factory()->for($this->merchant)->create();
    $branchB = MerchantBranch::factory()->for($this->merchant)->create();
    $this->promotion->update(['branch_id' => $branchA->id]);

    $recorder = app(CreditRecorder::class);
    $record = fn (string $invoice, ?int $branchId) => $recorder->record(
        merchant: $this->merchant,
        actor: Actor::merchantUser($this->user->id),
        origin: 'pos',
        customerCode: '482917',
        invoiceNo: $invoice,
        eligible: Laari::of(125000),
        saleAmount: null,
        occurredAt: CarbonImmutable::now('UTC')->subHour(),
        branchId: $branchId,
    );

    $onBranch = $record('INV-BR-A', $branchA->id);
    $offBranch = $record('INV-BR-B', $branchB->id);
    $noBranch = $record('INV-BR-NONE', null);

    expect($onBranch->rate_bp)->toBe(500)
        ->and($onBranch->promotion_id)->toBe($this->promotion->id)
        ->and($offBranch->rate_bp)->toBe(200)
        ->and($offBranch->promotion_id)->toBeNull()
        ->and($noBranch->rate_bp)->toBe(200)
        ->and($noBranch->promotion_id)->toBeNull();

    expect((new Balances)->journalsAllBalance())->toBeTrue();
});

it('lets the standing rate win when it has risen past the promo rate since publish — a promo never pays less', function () {
    // Standing history: 200bp until an hour ago, then 700bp. The 500bp promo
    // was a boost when published; at today's occurred_at it no longer is.
    $boundary = now()->subHour();
    MerchantRate::query()->where('merchant_id', $this->merchant->id)->update(['effective_to' => $boundary]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 700,
        'effective_from' => $boundary,
        'effective_to' => null,
    ]);

    $this->postJson('/api/merchant/credits', promoCreditPayload([
        'occurred_at' => now()->subMinutes(10)->toIso8601String(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '7.00')
        ->assertJsonPath('data.platform_fee_percent', '1.00');

    expect(Transaction::query()->sole()->promotion_id)->toBeNull();
});

it('freezes standing terms on a below-merchant-minimum sale even inside a promo window', function () {
    // Below the merchant minimum nothing accrues at all; the zeroed row
    // evidences the standing terms it failed against and no promo is stamped.
    $this->promotion->update(['min_purchase_laari' => 1000]);

    $this->postJson('/api/merchant/credits', promoCreditPayload(['eligible_amount' => 4999]))
        ->assertCreated()
        ->assertJsonPath('data.state', 'reversed')
        ->assertJsonPath('data.reason_code', 'below_minimum')
        ->assertJsonPath('data.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.cashback_laari', 0);

    expect(Transaction::query()->sole()->promotion_id)->toBeNull();
});
