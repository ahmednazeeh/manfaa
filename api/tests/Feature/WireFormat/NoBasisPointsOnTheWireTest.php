<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\WireFormat\WireFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN §1 "API wire format": `rate_bp` / `fee_bp` — and every other
 * basis-point field — must not appear in ANY response body. Rates are
 * 2-decimal percent STRINGS, the same idiom `cashback_mvr` already uses.
 *
 * Each case asserts BOTH halves: the percent strings are present with the
 * exact expected digits, and no `*_bp` key exists anywhere in the body, at
 * any depth.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200, // standing 2.00% → §4 fee tier 0.75%
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'mode' => 'rate', 'rate_bp' => 100, 'active' => true, 'sort' => 2,
    ]);

    $this->token = $this->merchant->createToken('till', ['transactions:write', 'transactions:reverse', 'rates:read'])->plainTextToken;
});

/**
 * A vendor request: bearer credential plus the mandatory idempotency key.
 *
 * @return array<string, string>
 */
function wireHeaders(): array
{
    return [
        'Authorization' => 'Bearer '.test()->token,
        'Idempotency-Key' => (string) Str::uuid(),
    ];
}

/**
 * Asserts the published wire law on one response and returns it.
 */
function assertNoBasisPoints(TestResponse $response): TestResponse
{
    $offenders = WireFixture::basisPointKeys($response->json());

    expect($offenders)->toBe([], 'basis points must never reach the wire: '.implode(', ', $offenders));

    return $response;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wireSalePayload(array $overrides = []): array
{
    return [
        'invoice_no' => 'INV-'.random_int(100000, 999999),
        'customer_ref' => '482917',
        'eligible_amount' => 118000,
        'sale_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];
}

it('quotes percent strings and no basis points on POST /v1/transactions', function () {
    $response = $this->withHeaders(wireHeaders())
        ->postJson('/api/v1/transactions', wireSalePayload())
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75')
        // The money itself is unchanged: §4 ceiling integers.
        ->assertJsonPath('transaction.cashback_laari', 2360)
        ->assertJsonPath('transaction.fee_laari', 885);

    assertNoBasisPoints($response);

    expect($response->json('transaction'))->not->toHaveKey('rate_bp')
        ->and($response->json('transaction'))->not->toHaveKey('fee_bp');
});

it('quotes percent strings and no basis points on a LINED POST /v1/transactions', function () {
    $response = $this->withHeaders(wireHeaders())
        ->postJson('/api/v1/transactions', wireSalePayload([
            'eligible_amount' => 100000,
            'sale_amount' => 100000,
            'lines' => [
                ['category' => 'fruits', 'amount_laari' => 20000],
                ['category' => 'veggies', 'amount_laari' => 30000],
                ['category' => null, 'amount_laari' => 50000],
            ],
        ]))
        ->assertCreated()
        // Excluded line: 0.00% and no fee, spelled out rather than absent.
        ->assertJsonPath('transaction.lines.0.cashback_rate_percent', '0.00')
        ->assertJsonPath('transaction.lines.0.platform_fee_percent', '0.00')
        ->assertJsonPath('transaction.lines.1.cashback_rate_percent', '1.00')
        ->assertJsonPath('transaction.lines.1.platform_fee_percent', '0.50')
        ->assertJsonPath('transaction.lines.2.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.lines.2.platform_fee_percent', '0.75');

    assertNoBasisPoints($response);
});

it('quotes percent strings and no basis points on POST /v1/transactions/{id}/reverse', function () {
    $id = $this->withHeaders(wireHeaders())
        ->postJson('/api/v1/transactions', wireSalePayload())
        ->assertCreated()
        ->json('transaction.id');

    $response = $this->withHeaders(wireHeaders())
        ->postJson("/api/v1/transactions/{$id}/reverse", [
            'reason' => 'customer_refund',
            'occurred_at' => now()->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75');

    assertNoBasisPoints($response);
});

it('quotes percent strings and no basis points on GET /v1/merchants/me/rate, including pending_decrease and active_promotion', function () {
    // A scheduled decrease (tomorrow 00:00 business time) and a live promo.
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 150,
        'effective_from' => now('Indian/Maldives')->addDay()->startOfDay()->utc(),
        'effective_to' => null,
    ]);
    $this->merchant->promotions()->create([
        'rate_bp' => 500,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
    ]);

    $response = $this->withHeaders(wireHeaders())
        ->getJson('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('cashback_rate_percent', '2.00')
        ->assertJsonPath('platform_fee_percent', '0.75')
        ->assertJsonPath('pending_decrease.cashback_rate_percent', '1.50')
        ->assertJsonPath('pending_decrease.platform_fee_percent', '0.50')
        ->assertJsonPath('active_promotion.cashback_rate_percent', '5.00')
        ->assertJsonPath('active_promotion.platform_fee_percent', '1.00');

    assertNoBasisPoints($response);
});

it('quotes percent strings and no basis points on GET /v1/merchants/me/product-categories', function () {
    $response = $this->withHeaders(wireHeaders())
        ->getJson('/api/v1/merchants/me/product-categories')
        ->assertOk()
        ->assertJsonPath('data.0.cashback_rate_percent', null) // excluded
        ->assertJsonPath('data.1.cashback_rate_percent', '1.00');

    assertNoBasisPoints($response);
});

it('quotes percent strings and no basis points on the merchant panel credit, category and rate surfaces', function () {
    $this->actingAs($this->owner, 'merchant');

    $credit = $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-9001',
        'eligible_amount' => 125000,
        'sale_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.platform_fee_percent', '0.75');

    assertNoBasisPoints($credit);

    assertNoBasisPoints(
        $this->getJson('/api/merchant/product-categories')
            ->assertOk()
            ->assertJsonPath('data.1.cashback_rate_percent', '1.00')
    );

    assertNoBasisPoints(
        $this->getJson('/api/merchant/rate')
            ->assertOk()
            ->assertJsonPath('data.current.cashback_rate_percent', '2.00')
            ->assertJsonPath('data.current.platform_fee_percent', '0.75')
            ->assertJsonPath('data.current.all_in_percent', '2.75')
    );

    assertNoBasisPoints(
        $this->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])
            ->assertOk()
            ->assertJsonPath('change.new.cashback_rate_percent', '3.00')
            ->assertJsonPath('change.new.all_in_percent', '3.75')
    );

    assertNoBasisPoints($this->getJson('/api/merchant/transactions')->assertOk());
});

it('quotes percent strings and no basis points on the merchant promotion surfaces', function () {
    $this->actingAs($this->owner, 'merchant');

    $response = $this->postJson('/api/merchant/promotions', [
        'cashback_rate_percent' => '5.00',
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDays(8)->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '5.00')
        ->assertJsonPath('data.platform_fee_percent', '1.00')
        ->assertJsonPath('cost_preview.promo.all_in_percent', '6.00')
        ->assertJsonPath('cost_preview.standing.all_in_percent', '2.75')
        ->assertJsonPath('cost_preview.all_in_delta_percent', '3.25');

    assertNoBasisPoints($response);
});

it('quotes percent strings and no basis points on the admin platform settings and fee tier surfaces', function () {
    $this->actingAs(AdminUser::factory()->create(), 'admin');

    // A platform SETTING that holds a rate is no exception: the prompt-
    // payment discount is stored in basis points and travels as a percent,
    // under a `_percent` name, in both directions.
    assertNoBasisPoints(
        $this->getJson('/api/admin/platform/settings')
            ->assertOk()
            ->assertJsonPath('data.prompt_discount_rate_percent.value', '5.00')
            ->assertJsonPath('data.prompt_discount_rate_percent.max', '20.00')
            // Keys in laari or days keep the plain integer they are.
            ->assertJsonPath('data.min_payout_laari.value', 10000)
            ->assertJsonPath('data.prompt_discount_max_age_days.value', 10)
    );

    assertNoBasisPoints(
        $this->patchJson('/api/admin/platform/settings/prompt_discount_rate_percent', ['value' => '7.50'])
            ->assertOk()
            ->assertJsonPath('data.prompt_discount_rate_percent.value', '7.50')
    );

    // Stored as integer basis points all the same.
    expect(app(PlatformConfig::class)->promptDiscountRateBp())->toBe(750);

    assertNoBasisPoints($this->getJson('/api/admin/platform/fee-tiers')->assertOk());
});

it('quotes percent strings and no basis points on the public storefront and discovery surfaces', function () {
    assertNoBasisPoints(
        $this->getJson('/api/discover/merchants/'.$this->merchant->slug)
            ->assertOk()
            ->assertJsonPath('data.cashback_rate_percent', '2.00')
            ->assertJsonPath('data.standing_cashback_rate_percent', '2.00')
    );

    assertNoBasisPoints($this->getJson('/api/discover/merchants')->assertOk());
});
