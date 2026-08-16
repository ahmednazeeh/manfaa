<?php

declare(strict_types=1);

use App\Domain\Cashback\RateBelowAdvertisedException;
use App\Domain\Platform\RateNotPricedException;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN §1 "Per-sale rate override" (decision 2026-08-15): POST
 * /v1/transactions and the merchant manual credit accept an optional
 * `cashback_rate_percent` that prices THAT SALE only.
 *
 * The law under test:
 *  - it may only BOOST — never below the rate the sale would otherwise earn
 *    (standing, or a live promotion covering it) → `rate_below_advertised`;
 *  - it must be priceable by the ACTIVE fee tier schedule → `rate_not_priced`;
 *  - the applied rate freezes on the row exactly as always, and the fee tier
 *    follows the APPLIED rate;
 *  - with line pricing it becomes the rate of every line that would
 *    otherwise price at standing, and the promotion floor still holds per
 *    line;
 *  - it only BOOSTS, so a value equal to the advertised rate changes
 *    nothing at all — the promotion that advertised it still prices the
 *    sale under its own per-customer cap;
 *  - a sale that grants no cashback either way (suspended-merchant
 *    ingestion, below minimum) ignores it rather than being refused;
 *  - on the panel it is a MANAGER's decision, like every other rate.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200, // standing 2.00%
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    $this->token = $this->merchant->createToken('till', ['transactions:write', 'rates:read'])->plainTextToken;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function overrideSale(array $overrides = []): array
{
    return [
        'invoice_no' => 'INV-'.random_int(100000, 999999),
        'customer_ref' => '482917',
        'eligible_amount' => 118000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postOverrideSale(array $payload): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', $payload);
}

it('boosts one sale: 2% standing, 5% override → 500bp frozen, fee tier 1.00%, exact laari', function () {
    postOverrideSale(overrideSale(['cashback_rate_percent' => '5']))
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '5.00')
        // The fee follows the APPLIED rate into the §4 500–2000 band.
        ->assertJsonPath('transaction.platform_fee_percent', '1.00')
        // ceil(118000 * 500 / 10000) = 5900; ceil(118000 * 100 / 10000) = 1180.
        ->assertJsonPath('transaction.cashback_laari', 5900)
        ->assertJsonPath('transaction.cashback_mvr', '59.00')
        ->assertJsonPath('transaction.fee_laari', 1180);

    $transaction = Transaction::query()->sole();

    // Frozen on the row in basis points, exactly as always — the wire idiom
    // changed, the storage law did not.
    expect($transaction->rate_bp)->toBe(500)
        ->and($transaction->fee_bp)->toBe(100)
        ->and($transaction->cashback_laari)->toBe(5900)
        ->and($transaction->fee_laari)->toBe(1180)
        // A boost is not a promotion: nothing is stamped, no cap consumed.
        ->and($transaction->promotion_id)->toBeNull();

    // The merchant's standing rate is untouched — the override was for THIS
    // sale only.
    expect(MerchantRate::query()->where('merchant_id', $this->merchant->id)->max('rate_bp'))->toBe(200);
});

it('accepts the override as a JSON number and as a 2-decimal string alike', function (mixed $sent, int $expectedBp) {
    postOverrideSale(overrideSale(['cashback_rate_percent' => $sent]))->assertCreated();

    expect(Transaction::query()->sole()->rate_bp)->toBe($expectedBp);
})->with([
    'string integer' => ['5', 500],
    'json integer' => [5, 500],
    'string with decimals' => ['2.50', 250],
    'json number' => [2.5, 250],
]);

it('refuses an override below the standing rate with rate_below_advertised', function () {
    postOverrideSale(overrideSale(['cashback_rate_percent' => '1.00']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', RateBelowAdvertisedException::CODE)
        ->assertJsonPath('error.meta.advertised_cashback_rate_percent', '2.00');

    // The advertised rate is a promise: nothing was recorded at the lower
    // rate, and nothing was recorded at all.
    expect(Transaction::query()->count())->toBe(0);
});

it('accepts an override exactly equal to the standing rate (a no-op boost)', function () {
    postOverrideSale(overrideSale(['cashback_rate_percent' => '2.00']))->assertCreated();

    expect(Transaction::query()->sole()->rate_bp)->toBe(200);
});

it('refuses an override above the active schedule ceiling with rate_not_priced', function () {
    // The seeded schedule prices 0.50%–10.00%; 15% has no fee anywhere.
    postOverrideSale(overrideSale(['cashback_rate_percent' => '15.00']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', RateNotPricedException::CODE)
        ->assertJsonPath('error.meta.ceiling_percent', '10.00');

    expect(Transaction::query()->count())->toBe(0);
});

it('refuses a structurally impossible override as a field error, never a code', function () {
    postOverrideSale(overrideSale(['cashback_rate_percent' => '2.555']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['errors' => ['cashback_rate_percent']]]);

    expect(Transaction::query()->count())->toBe(0);
});

it('never lets an override pay less than a live promotion', function () {
    // Live 6% promotion — the rate the customer is advertised right now.
    $this->merchant->promotions()->create([
        'rate_bp' => 600,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
    ]);

    // 5% is above STANDING but below the live promo: refused, because the
    // promo rate is what the storefront promises today.
    postOverrideSale(overrideSale(['cashback_rate_percent' => '5.00']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', RateBelowAdvertisedException::CODE)
        ->assertJsonPath('error.meta.advertised_cashback_rate_percent', '6.00');

    expect(Transaction::query()->count())->toBe(0);

    // 8% clears the promotion and prices the sale; the promo neither stamps
    // the row nor consumes its cap.
    postOverrideSale(overrideSale(['cashback_rate_percent' => '8.00']))
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '8.00')
        ->assertJsonPath('transaction.platform_fee_percent', '1.00')
        ->assertJsonPath('transaction.cashback_laari', 9440); // ceil(118000*800/10000)

    $transaction = Transaction::query()->sole();

    expect($transaction->promotion_id)->toBeNull()
        // Strictly more than the 6% promotion would have paid.
        ->and($transaction->cashback_laari)->toBeGreaterThan(intdiv(118000 * 600 + 9999, 10000));
});

it('leaves a live promotion — and its per-customer cap — in force when the override merely equals it', function () {
    // The offer the storefront advertises is "6%, up to 40.00 per customer".
    // The cap is part of that offer, so echoing the 6% back as a per-sale
    // override must not convert it into an uncapped 6%.
    $this->merchant->promotions()->create([
        'rate_bp' => 600,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
        'max_cashback_per_customer_laari' => 4000,
    ]);

    // First sale: the promotion prices it and the cap clips it, exactly as
    // it would with no override in the payload.
    postOverrideSale(overrideSale(['cashback_rate_percent' => '6.00']))
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '6.00')
        // ceil(118000 * 600 / 10000) = 7080, clipped to the 4000 headroom.
        ->assertJsonPath('transaction.cashback_laari', 4000);

    $first = Transaction::query()->latest('id')->first();

    // Stamped, so the headroom it consumed is accounted for.
    expect($first->promotion_id)->not->toBeNull();

    // Second sale, same customer, same echoed override: the cap is spent, so
    // the sale earns the standing rate — NOT another uncapped 6%.
    postOverrideSale(overrideSale(['cashback_rate_percent' => '6.00']))
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.cashback_laari', 2360);

    $second = Transaction::query()->latest('id')->first();

    expect($second->rate_bp)->toBe(200)
        ->and($second->promotion_id)->toBeNull()
        // The whole point: 4000 was the merchant's exposure bound for this
        // customer on this promotion, and it held.
        ->and((int) Transaction::query()->whereNotNull('promotion_id')->sum('cashback_laari'))->toBe(4000);
});

it('keeps the promotion cap on a LINED sale when the override equals the promo rate', function () {
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'mode' => 'rate', 'rate_bp' => 100, 'active' => true, 'sort' => 1,
    ]);

    $this->merchant->promotions()->create([
        'rate_bp' => 600,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
        'max_cashback_per_customer_laari' => 2000,
    ]);

    postOverrideSale(overrideSale([
        'eligible_amount' => 100000,
        'cashback_rate_percent' => '6.00',
        'lines' => [
            ['category' => 'veggies', 'amount_laari' => 40000],
            ['category' => null, 'amount_laari' => 60000],
        ],
    ]))
        ->assertCreated()
        // The promo lifts the 1% line and the cap clips it at 2000.
        ->assertJsonPath('transaction.lines.0.cashback_rate_percent', '6.00')
        ->assertJsonPath('transaction.lines.0.priced_by', 'promotion')
        ->assertJsonPath('transaction.lines.0.cashback_laari', 2000)
        // The default line does NOT get to pay the promo rate outside the
        // cap: with the headroom spent it prices at the standing rate.
        ->assertJsonPath('transaction.lines.1.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.lines.1.priced_by', 'standing')
        ->assertJsonPath('transaction.lines.1.cashback_laari', 1200)
        ->assertJsonPath('transaction.cashback_laari', 3200);

    // Only the promotion-priced line consumed headroom, and only up to it.
    expect((int) TransactionLine::query()->where('priced_by', 'promotion')->sum('cashback_laari'))->toBe(2000);
});

it('ignores an unusable override on a sale that grants no cashback, and records it (§7, §9.2)', function () {
    $this->merchant->update(['status' => 'suspended']);

    // §7: suspension stops cashback CREATION, not ingestion. The till keeps
    // POSTing and must be answered — an override it could never have earned
    // is not a reason to reject the sale.
    postOverrideSale(overrideSale(['cashback_rate_percent' => '1.00']))
        ->assertOk()
        ->assertJsonPath('status', 'recorded_ineligible')
        ->assertJsonPath('transaction.cashback_laari', 0)
        // The row freezes the standing terms it failed against.
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00');

    // Same for a rate the platform cannot price at all.
    postOverrideSale(overrideSale(['cashback_rate_percent' => '15.00']))
        ->assertOk()
        ->assertJsonPath('status', 'recorded_ineligible')
        ->assertJsonPath('transaction.cashback_laari', 0);

    expect(Transaction::query()->count())->toBe(2);
});

it('ignores an unusable override on a below-minimum sale and still returns 200 (§9.2)', function () {
    postOverrideSale(overrideSale(['eligible_amount' => 100, 'cashback_rate_percent' => '1.00']))
        ->assertOk()
        ->assertJsonPath('status', 'below_minimum')
        ->assertJsonPath('transaction.cashback_laari', 0)
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00');

    expect(Transaction::query()->sole()->reason_code)->toBe('below_minimum');
});

it('reserves the per-sale override for credits.custom_rate on the manual credit', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $payload = [
        'customer_code' => '482917',
        'invoice_no' => 'INV-7101',
        'eligible_amount' => 118000,
    ];

    // Staff key sales in — that is their job — but not at a rate of their
    // own choosing: the same authority the rate screen demands.
    $this->actingAs($staff, 'merchant')
        ->postJson('/api/merchant/credits', [...$payload, 'cashback_rate_percent' => '10.00'])
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_required')
        ->assertJsonPath('permission', 'credits.custom_rate');

    expect(Transaction::query()->count())->toBe(0);

    // The same sale without an override is ordinary staff work.
    $this->actingAs($staff, 'merchant')
        ->postJson('/api/merchant/credits', $payload)
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '2.00');

    // A manager may boost it.
    $manager = MerchantUser::factory()->for($this->merchant)->manager()->create();

    $this->actingAs($manager, 'merchant')
        ->postJson('/api/merchant/credits', [...$payload, 'invoice_no' => 'INV-7102', 'cashback_rate_percent' => '5.00'])
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '5.00');
});

it('prices a LINED sale with the override as the standing-line rate, exclusions and category rates untouched', function () {
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'mode' => 'rate', 'rate_bp' => 100, 'active' => true, 'sort' => 2,
    ]);

    postOverrideSale(overrideSale([
        'eligible_amount' => 100000,
        'cashback_rate_percent' => '5.00',
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 20000],
            ['category' => 'veggies', 'amount_laari' => 30000],
            ['category' => null, 'amount_laari' => 50000],
        ],
    ]))
        ->assertCreated()
        // The row snapshot is the base rate the sale priced at: the override.
        ->assertJsonPath('transaction.cashback_rate_percent', '5.00')
        ->assertJsonPath('transaction.platform_fee_percent', '1.00')
        // Excluded stays excluded — an override never pays an exclusion.
        ->assertJsonPath('transaction.lines.0.cashback_rate_percent', '0.00')
        ->assertJsonPath('transaction.lines.0.cashback_laari', 0)
        // A category override keeps its own rate: the per-sale override
        // replaces the STANDING rate, not the rate card.
        ->assertJsonPath('transaction.lines.1.cashback_rate_percent', '1.00')
        ->assertJsonPath('transaction.lines.1.cashback_laari', 300)
        ->assertJsonPath('transaction.lines.1.platform_fee_percent', '0.50')
        ->assertJsonPath('transaction.lines.1.fee_laari', 150)
        // The default bucket — which would have priced at standing — takes
        // the override.
        ->assertJsonPath('transaction.lines.2.cashback_rate_percent', '5.00')
        ->assertJsonPath('transaction.lines.2.cashback_laari', 2500)
        ->assertJsonPath('transaction.lines.2.platform_fee_percent', '1.00')
        ->assertJsonPath('transaction.lines.2.fee_laari', 500)
        // Totals are the SUM of the stored line integers (§4).
        ->assertJsonPath('transaction.cashback_laari', 2800)
        ->assertJsonPath('transaction.fee_laari', 650);

    $transaction = Transaction::query()->sole();

    expect($transaction->rate_bp)->toBe(500)
        ->and((int) TransactionLine::query()->where('transaction_id', $transaction->id)->sum('cashback_laari'))
        ->toBe($transaction->cashback_laari);
});

it('keeps the per-line promotion floor under an override: no line pays less than it would without it', function () {
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'mode' => 'rate', 'rate_bp' => 100, 'active' => true, 'sort' => 1,
    ]);

    // Live 6% promotion lifts every non-excluded line to max(promo, own).
    $this->merchant->promotions()->create([
        'rate_bp' => 600,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'status' => 'published',
    ]);

    postOverrideSale(overrideSale([
        'eligible_amount' => 100000,
        'cashback_rate_percent' => '8.00', // above the promo, so it is allowed
        'lines' => [
            ['category' => 'veggies', 'amount_laari' => 40000],
            ['category' => null, 'amount_laari' => 60000],
        ],
    ]))
        ->assertCreated()
        // The 1% category line still earns the 6% promotion — the floor
        // holds, the override never lowers a line.
        ->assertJsonPath('transaction.lines.0.cashback_rate_percent', '6.00')
        ->assertJsonPath('transaction.lines.0.priced_by', 'promotion')
        ->assertJsonPath('transaction.lines.0.cashback_laari', 2400)
        // The default line takes the override (8% beats the 6% promo).
        ->assertJsonPath('transaction.lines.1.cashback_rate_percent', '8.00')
        ->assertJsonPath('transaction.lines.1.priced_by', 'standing')
        ->assertJsonPath('transaction.lines.1.cashback_laari', 4800)
        ->assertJsonPath('transaction.cashback_laari', 7200);
});

it('applies the same override law to the merchant manual credit', function () {
    $this->actingAs($this->owner, 'merchant');

    $payload = [
        'customer_code' => '482917',
        'invoice_no' => 'INV-7001',
        'eligible_amount' => 118000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ];

    // Below the standing rate: refused with the same machine code.
    $this->postJson('/api/merchant/credits', [...$payload, 'cashback_rate_percent' => '1.00'])
        ->assertUnprocessable()
        ->assertJsonPath('code', RateBelowAdvertisedException::CODE);

    // Above the schedule ceiling: refused as unpriced.
    $this->postJson('/api/merchant/credits', [...$payload, 'cashback_rate_percent' => '15.00'])
        ->assertUnprocessable()
        ->assertJsonPath('code', RateNotPricedException::CODE);

    expect(Transaction::query()->count())->toBe(0);

    // A boost goes through, frozen on the row with the matching fee tier.
    $this->postJson('/api/merchant/credits', [...$payload, 'cashback_rate_percent' => '5.00'])
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '5.00')
        ->assertJsonPath('data.platform_fee_percent', '1.00')
        ->assertJsonPath('data.cashback_laari', 5900)
        ->assertJsonPath('data.fee_laari', 1180);

    expect(Transaction::query()->sole()->rate_bp)->toBe(500);
});

it('prices exactly as before when the override is omitted', function () {
    postOverrideSale(overrideSale())
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75')
        ->assertJsonPath('transaction.cashback_laari', 2360)
        ->assertJsonPath('transaction.fee_laari', 885);
});
