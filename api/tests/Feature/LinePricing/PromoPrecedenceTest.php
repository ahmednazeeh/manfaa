<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use App\Models\Transaction;
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
        'rate_bp' => 500, // standing 5%
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    Customer::factory()->create(['customer_code' => '482917']);

    // fruits excluded; veggies 200bp (LOWER than any promo below);
    // electronics 800bp (HIGHER than any promo below).
    foreach ([
        ['fruits', 'Fruits', 'excluded', null, 1],
        ['veggies', 'Veggies', 'rate', 200, 2],
        ['electronics', 'Electronics', 'rate', 800, 3],
    ] as [$slug, $name, $mode, $rate, $sort]) {
        MerchantProductCategory::query()->create([
            'merchant_id' => $this->merchant->id, 'slug' => $slug, 'name_en' => $name,
            'mode' => $mode, 'rate_bp' => $rate, 'active' => true, 'sort' => $sort,
        ]);
    }

    $this->actingAs($this->user, 'merchant');
});

function precedencePromo(int $rateBp, ?int $minPurchase = null): Promotion
{
    return Promotion::query()->create([
        'merchant_id' => test()->merchant->id,
        'rate_bp' => $rateBp,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'min_purchase_laari' => $minPurchase,
        'max_cashback_per_customer_laari' => null,
        'status' => 'published',
        'published_at' => now()->subDays(2),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function precedencePayload(array $overrides = []): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 10000],
            ['category' => 'veggies', 'amount_laari' => 20000],
            ['category' => 'electronics', 'amount_laari' => 30000],
            ['category' => null, 'amount_laari' => 40000],
        ],
        ...$overrides,
    ];
}

it('applies max(promo, own) per non-excluded line: promo 600 beats 200 and 500, loses to 800, never touches excluded', function () {
    // HAND DERIVATION (promo 600bp, its §4 fee tier 100bp):
    //   fruits      10,000 excluded → 0 / 0 — exclusions hold during promos
    //   veggies     20,000: own 200 < 600 → promo 600
    //               cashback intdiv(20,000·600+9,999, 10,000) = intdiv(12,009,999, 10,000) = 1,200
    //               fee intdiv(20,000·100+9,999, 10,000) = 200
    //   electronics 30,000: own 800 > 600 → own 800 (fee tier 100)
    //               cashback intdiv(30,000·800+9,999, 10,000) = 2,400; fee 300
    //   default     40,000: standing 500 < 600 → promo 600
    //               cashback intdiv(40,000·600+9,999, 10,000) = 2,400; fee 400
    //   TOTALS: cashback 1,200+2,400+2,400 = 6,000; fee 200+300+400 = 900
    $promo = precedencePromo(600);

    $this->postJson('/api/merchant/credits', precedencePayload())
        ->assertCreated()
        ->assertJsonPath('data.cashback_rate_percent', '5.00') // row keeps the standing snapshot
        ->assertJsonPath('data.platform_fee_percent', '1.00')
        ->assertJsonPath('data.cashback_laari', 6000)
        ->assertJsonPath('data.fee_laari', 900)
        ->assertJsonPath('data.lines.0.priced_by', 'excluded')
        ->assertJsonPath('data.lines.0.cashback_laari', 0)
        ->assertJsonPath('data.lines.0.fee_laari', 0)
        ->assertJsonPath('data.lines.1.priced_by', 'promotion')
        ->assertJsonPath('data.lines.1.cashback_rate_percent', '6.00')
        ->assertJsonPath('data.lines.1.cashback_laari', 1200)
        ->assertJsonPath('data.lines.1.fee_laari', 200)
        ->assertJsonPath('data.lines.2.priced_by', 'category')
        ->assertJsonPath('data.lines.2.cashback_rate_percent', '8.00')
        ->assertJsonPath('data.lines.2.cashback_laari', 2400)
        ->assertJsonPath('data.lines.2.fee_laari', 300)
        ->assertJsonPath('data.lines.3.priced_by', 'promotion')
        ->assertJsonPath('data.lines.3.cashback_rate_percent', '6.00')
        ->assertJsonPath('data.lines.3.cashback_laari', 2400)
        ->assertJsonPath('data.lines.3.fee_laari', 400);

    expect(Transaction::query()->sole()->promotion_id)->toBe($promo->id);
});

it('lets a promo below the standing rate still lift the lower category override only', function () {
    // Promo 300bp (fee tier 75bp) — beats veggies' 200, loses to standing
    // 500 and electronics' 800:
    //   veggies     20,000 @300 → intdiv(20,000·300+9,999, 10,000) = 600; fee 75bp → 150
    //   electronics 30,000 @own 800 → 2,400 / 300
    //   default     40,000 @standing 500 → intdiv(40,000·500+9,999, 10,000) = 2,000; fee 100bp → 400
    //   fruits      0 / 0
    //   TOTALS: cashback 600+2,400+2,000 = 5,000; fee 150+300+400 = 850
    $promo = precedencePromo(300);

    $this->postJson('/api/merchant/credits', precedencePayload())
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 5000)
        ->assertJsonPath('data.fee_laari', 850)
        ->assertJsonPath('data.lines.1.priced_by', 'promotion')
        ->assertJsonPath('data.lines.1.cashback_rate_percent', '3.00')
        ->assertJsonPath('data.lines.1.cashback_laari', 600)
        ->assertJsonPath('data.lines.1.platform_fee_percent', '0.75')
        ->assertJsonPath('data.lines.1.fee_laari', 150)
        ->assertJsonPath('data.lines.2.priced_by', 'category')
        ->assertJsonPath('data.lines.3.priced_by', 'standing')
        ->assertJsonPath('data.lines.3.cashback_laari', 2000);

    // Stamped: at least one line priced under the promotion.
    expect(Transaction::query()->sole()->promotion_id)->toBe($promo->id);
});

it('leaves the row unstamped when the promo boosts no line', function () {
    // Basket of only excluded + higher-override lines: promo 600 boosts
    // nothing (fruits excluded, electronics own 800 > 600) → own terms,
    // no promotion stamp.
    precedencePromo(600);

    $this->postJson('/api/merchant/credits', precedencePayload([
        'eligible_amount' => 40000,
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 10000],
            ['category' => 'electronics', 'amount_laari' => 30000],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 2400)
        ->assertJsonPath('data.fee_laari', 300)
        ->assertJsonPath('data.lines.0.priced_by', 'excluded')
        ->assertJsonPath('data.lines.1.priced_by', 'category');

    expect(Transaction::query()->sole()->promotion_id)->toBeNull();
});

it('prices lines at own terms without any live promotion', function () {
    //   veggies     20,000 @200 → intdiv(20,000·200+9,999, 10,000) = 400; fee 75bp → 150
    //   electronics 30,000 @800 → 2,400 / 300
    //   default     40,000 @500 → 2,000 / 400
    //   TOTALS: cashback 400+2,400+2,000 = 4,800; fee 150+300+400 = 850
    $this->postJson('/api/merchant/credits', precedencePayload())
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 4800)
        ->assertJsonPath('data.fee_laari', 850)
        ->assertJsonPath('data.lines.1.priced_by', 'category')
        ->assertJsonPath('data.lines.1.cashback_laari', 400)
        ->assertJsonPath('data.lines.2.priced_by', 'category')
        ->assertJsonPath('data.lines.3.priced_by', 'standing');

    expect(Transaction::query()->sole()->promotion_id)->toBeNull();
});

it('evaluates promo min_purchase against the WHOLE eligible amount, not per line', function () {
    // min_purchase 50,000: a 60,000 sale qualifies even though the veggies
    // line alone (20,000) is under it — the veggies line earns the promo.
    $promo = precedencePromo(600, minPurchase: 50000);

    $this->postJson('/api/merchant/credits', precedencePayload([
        'eligible_amount' => 60000,
        'lines' => [
            ['category' => 'veggies', 'amount_laari' => 20000],
            ['category' => null, 'amount_laari' => 40000],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.priced_by', 'promotion')
        ->assertJsonPath('data.lines.0.cashback_laari', 1200)
        ->assertJsonPath('data.lines.1.priced_by', 'promotion')
        ->assertJsonPath('data.lines.1.cashback_laari', 2400);

    expect(Transaction::query()->latest('id')->first()->promotion_id)->toBe($promo->id);

    // A 40,000 sale does not qualify — everything at own terms.
    $this->postJson('/api/merchant/credits', precedencePayload([
        'eligible_amount' => 40000,
        'lines' => [
            ['category' => 'veggies', 'amount_laari' => 20000],
            ['category' => null, 'amount_laari' => 20000],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.priced_by', 'category')
        ->assertJsonPath('data.lines.0.cashback_laari', 400)
        ->assertJsonPath('data.lines.1.priced_by', 'standing')
        ->assertJsonPath('data.lines.1.cashback_laari', 1000);

    expect(Transaction::query()->latest('id')->first()->promotion_id)->toBeNull();
});
