<?php

use App\Domain\Money\Percent;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A lined sale has no single cashback rate. The row carries the BASE terms
 * (the standing rate frozen at occurred_at) because that is what the sale
 * was priced AGAINST; what it actually EARNED is the effective pair, and on
 * a mixed basket the two genuinely differ. These pin that difference so it
 * can never quietly collapse back into one misleading number.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'status' => 'active',
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500, // standing 5%
        'effective_from' => CarbonImmutable::now('UTC')->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
    $this->token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;
    $this->ratesToken = $this->merchant->createToken('display', ['rates:read'])->plainTextToken;

    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'name_dv' => 'މޭވާ', 'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'name_dv' => 'ތަރުކާރީ', 'mode' => 'rate', 'rate_bp' => 200, 'active' => true, 'sort' => 2,
    ]);
});

it('reports the earned rate beside the base rate on a mixed basket', function () {
    $this->actingAs($this->user, 'merchant');

    $response = $this->postJson('/api/merchant/credits', [
        'customer_code' => $this->customer->customer_code,
        'invoice_no' => 'INV-MIX-1',
        'eligible_amount' => 100000,
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => 'veggies', 'amount_laari' => 25000],
            ['category' => null, 'amount_laari' => 45000],
        ],
    ])->assertCreated();

    // Earned 2,750 on 100,000 = 2.75%, against a 5.00% base rate that
    // applied to only 45,000 of the basket. Fee 638 = 0.64% (0.638 rounded
    // half-up to the nearest basis point).
    $response
        ->assertJsonPath('data.cashback_laari', 2750)
        ->assertJsonPath('data.fee_laari', 638)
        ->assertJsonPath('data.cashback_rate_percent', '5.00')
        ->assertJsonPath('data.platform_fee_percent', '1.00')
        ->assertJsonPath('data.effective_cashback_rate_percent', '2.75')
        ->assertJsonPath('data.effective_platform_fee_percent', '0.64');
});

it('agrees with the base rate on a single-rate sale', function () {
    $this->actingAs($this->user, 'merchant');

    // 100,000 @ 500bp = 5,000 exactly, so the two rates coincide — which is
    // why the effective pair is safe to read on EVERY sale, not just lined
    // ones.
    $this->postJson('/api/merchant/credits', [
        'customer_code' => $this->customer->customer_code,
        'invoice_no' => 'INV-FLAT-1',
        'eligible_amount' => 100000,
    ])
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 5000)
        ->assertJsonPath('data.cashback_rate_percent', '5.00')
        ->assertJsonPath('data.effective_cashback_rate_percent', '5.00');
});

/**
 * A zeroed row is the one place a bare "0.00" would actively mislead — it
 * would read as "this store pays nothing" rather than "this sale earned
 * nothing, for a reason the row states".
 */
it('answers null rather than 0.00 when there is nothing to divide', function () {
    expect(Percent::effectiveRate(0, 0))->toBeNull()
        ->and(Percent::effectiveRate(2750, 0))->toBeNull()
        // A real zero on a real basket IS 0.00 — the store excluded
        // everything, which is a fact rather than a missing value.
        ->and(Percent::effectiveRate(0, 100000))->toBe('0.00');
});

it('rounds the earned rate half-up to the nearest basis point', function () {
    // 638/100000 = 0.638% -> 0.64%; 632/100000 = 0.632% -> 0.63%.
    expect(Percent::effectiveRate(638, 100000))->toBe('0.64')
        ->and(Percent::effectiveRate(632, 100000))->toBe('0.63')
        // Exactly halfway rounds up.
        ->and(Percent::effectiveRate(635, 100000))->toBe('0.64')
        // A tiny sale that rounded its cashback UP can earn more than its
        // headline rate, and the effective rate says so honestly.
        ->and(Percent::effectiveRate(1, 10))->toBe('10.00');
});

it('exposes the earned rate on the vendor API too, beside the per-line rates', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'INV-MIX-2',
        'customer_ref' => $this->customer->customer_code,
        'eligible_amount' => 100000,
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => 'veggies', 'amount_laari' => 25000],
            ['category' => null, 'amount_laari' => 45000],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('transaction.effective_cashback_rate_percent', '2.75')
        ->assertJsonPath('transaction.effective_platform_fee_percent', '0.64')
        // The per-line rates remain the authoritative detail.
        ->assertJsonPath('transaction.lines.0.cashback_rate_percent', '0.00')
        ->assertJsonPath('transaction.lines.1.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.lines.2.cashback_rate_percent', '5.00')
        ->assertJsonPath('transaction.lines.1.platform_fee_percent', '0.75')
        ->assertJsonPath('transaction.lines.2.platform_fee_percent', '1.00');

    expect(Transaction::query()->where('invoice_no', 'INV-MIX-2')->sole()->cashback_laari)->toBe(2750);
});

/**
 * The same class of defect one level up: a till that reads only the
 * headline rate would print "5%" for a store that excludes fruit. The rate
 * endpoint now says whether the basket matters.
 */
it('warns the till that this store prices by category', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->ratesToken])
        ->getJson('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('cashback_rate_percent', '5.00')
        ->assertJsonPath('has_category_overrides', true);

    // Deactivate both overrides and the headline rate IS the whole story.
    MerchantProductCategory::query()
        ->where('merchant_id', $this->merchant->id)
        ->update(['active' => false]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->ratesToken])
        ->getJson('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('has_category_overrides', false);
});
