<?php

declare(strict_types=1);

use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use App\Models\Transaction;
use App\Models\TransactionLine;
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
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

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

function capLinesPromo(int $capLaari): Promotion
{
    return Promotion::query()->create([
        'merchant_id' => test()->merchant->id,
        'rate_bp' => 600,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'min_purchase_laari' => null,
        'max_cashback_per_customer_laari' => $capLaari,
        'status' => 'published',
        'published_at' => now()->subDays(2),
    ]);
}

/**
 * @param  list<array{category: string|null, amount_laari: int}>  $lines
 * @return array<string, mixed>
 */
function capLinesPayload(array $lines, array $overrides = []): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
        'eligible_amount' => array_sum(array_column($lines, 'amount_laari')),
        'occurred_at' => now()->subHour()->toIso8601String(),
        'lines' => $lines,
        ...$overrides,
    ];
}

it('consumes the per-customer cap only through promo-priced lines, in submitted order, with exact clip integers', function () {
    // Promo 600bp (fee tier 100bp), cap 3,000 per customer.
    //
    // CREDIT 1 — [electronics 30,000, veggies 20,000, default 5,500, fruits 4,500]:
    //   electronics own 800 > 600 → OWN terms, NOT cap-constrained:
    //     cashback intdiv(30,000·800+9,999, 10,000) = 2,400; fee 100bp → 300
    //   veggies promo normal intdiv(20,000·600+9,999, 10,000) = 1,200 ≤ 3,000
    //     → granted 1,200; fee intdiv(20,000·100+9,999, 10,000) = 200; remaining 1,800
    //   default promo normal intdiv(5,500·600+9,999, 10,000) = intdiv(3,309,999, 10,000) = 330 ≤ 1,800
    //     → granted 330; fee intdiv(5,500·100+9,999, 10,000) = 55; remaining 1,470
    //   fruits excluded → 0/0
    //   TOTALS: cashback 2,400+1,200+330 = 3,930; fee 300+200+55 = 555
    //   Cap consumed by PROMO lines only: 1,200 + 330 = 1,530 (not 3,930).
    $promo = capLinesPromo(3000);

    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => 'electronics', 'amount_laari' => 30000],
        ['category' => 'veggies', 'amount_laari' => 20000],
        ['category' => null, 'amount_laari' => 5500],
        ['category' => 'fruits', 'amount_laari' => 4500],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 3930)
        ->assertJsonPath('data.fee_laari', 555)
        ->assertJsonPath('data.lines.0.priced_by', 'category')
        ->assertJsonPath('data.lines.0.cashback_laari', 2400)
        ->assertJsonPath('data.lines.1.priced_by', 'promotion')
        ->assertJsonPath('data.lines.1.cashback_laari', 1200)
        ->assertJsonPath('data.lines.2.priced_by', 'promotion')
        ->assertJsonPath('data.lines.2.cashback_laari', 330)
        ->assertJsonPath('data.lines.3.priced_by', 'excluded');

    expect(Transaction::query()->latest('id')->first()->promotion_id)->toBe($promo->id);

    // CREDIT 2 — [veggies 20,000]: remaining cap must be 3,000 − 1,530 =
    // 1,470 (the 2,400 electronics line consumed NOTHING). Promo normal
    // 1,200 ≤ 1,470 → granted in full.
    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => 'veggies', 'amount_laari' => 20000],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.priced_by', 'promotion')
        ->assertJsonPath('data.lines.0.effective_rate_bp', 600)
        ->assertJsonPath('data.lines.0.cashback_laari', 1200)
        ->assertJsonPath('data.lines.0.fee_laari', 200);

    // CREDIT 3 — [veggies 20,000]: remaining 270. Clip 270 < own-rate
    // result intdiv(20,000·200+9,999, 10,000) = 400 → PER-LINE FLOOR: the
    // line reprices at its own 200bp (400 / fee 75bp → 150), consumes no
    // headroom, and the row is NOT stamped.
    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => 'veggies', 'amount_laari' => 20000],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.priced_by', 'category')
        ->assertJsonPath('data.lines.0.effective_rate_bp', 200)
        ->assertJsonPath('data.lines.0.cashback_laari', 400)
        ->assertJsonPath('data.lines.0.fee_laari', 150);

    expect(Transaction::query()->latest('id')->first()->promotion_id)->toBeNull();

    // CREDIT 4 — [default 5,000]: own standing intdiv(5,000·500+9,999,
    // 10,000) = 250; promo normal intdiv(5,000·600+9,999, 10,000) = 300 >
    // remaining 270 → clip 270 ≥ own 250 → granted 270, fee follows the
    // GRANTED reward: ceil(270·100/600) = intdiv(270·100+599, 600) =
    // intdiv(27,599, 600) = 45.
    expect(intdiv(270 * 100 + 599, 600))->toBe(45);

    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => null, 'amount_laari' => 5000],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.priced_by', 'promotion')
        ->assertJsonPath('data.lines.0.effective_rate_bp', 600)
        ->assertJsonPath('data.lines.0.cashback_laari', 270)
        ->assertJsonPath('data.lines.0.fee_laari', 45);

    // The invariant: promo-priced line cashback for this customer sums to
    // EXACTLY the cap — 1,200 + 330 + 1,200 + 270 = 3,000.
    $promoGranted = (int) TransactionLine::query()
        ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
        ->where('transactions.promotion_id', $promo->id)
        ->where('transactions.customer_id', $this->customer->id)
        ->where('transactions.state', '!=', 'reversed')
        ->where('transaction_lines.priced_by', 'promotion')
        ->sum('transaction_lines.cashback_laari');

    expect($promoGranted)->toBe(3000)
        ->and((new Balances)->journalsAllBalance())->toBeTrue();
});

it('clips in submitted order — reordering the same basket changes which lines the cap feeds', function () {
    // Cap 1,400, promo 600bp. Basket {veggies 20,000, default 5,500}.
    //
    // Customer A submits [veggies, default]:
    //   veggies promo 1,200 ≤ 1,400 → granted 1,200/200; remaining 200
    //   default promo normal 330 > 200 → clip 200 < own standing
    //     intdiv(5,500·500+9,999, 10,000) = 275 → FLOOR → standing 275,
    //     fee intdiv(5,500·100+9,999, 10,000) = 55
    //   totals: cashback 1,475; fee 255; promo consumed 1,200
    capLinesPromo(1400);

    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => 'veggies', 'amount_laari' => 20000],
        ['category' => null, 'amount_laari' => 5500],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 1475)
        ->assertJsonPath('data.fee_laari', 255)
        ->assertJsonPath('data.lines.0.priced_by', 'promotion')
        ->assertJsonPath('data.lines.0.cashback_laari', 1200)
        ->assertJsonPath('data.lines.1.priced_by', 'standing')
        ->assertJsonPath('data.lines.1.cashback_laari', 275)
        ->assertJsonPath('data.lines.1.fee_laari', 55);

    // Customer B submits [default, veggies]:
    //   default promo 330 ≤ 1,400 → granted 330/55; remaining 1,070
    //   veggies promo normal 1,200 > 1,070 → clip 1,070 ≥ own 400 →
    //     granted 1,070; fee follows granted: ceil(1,070·100/600) =
    //     intdiv(1,070·100+599, 600) = intdiv(107,599, 600) = 179
    //   totals: cashback 1,400; fee 234; promo consumed 1,400 (cap exact)
    expect(intdiv(1070 * 100 + 599, 600))->toBe(179);

    Customer::factory()->create(['customer_code' => '104433']);

    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => null, 'amount_laari' => 5500],
        ['category' => 'veggies', 'amount_laari' => 20000],
    ], ['customer_code' => '104433']))
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 1400)
        ->assertJsonPath('data.fee_laari', 234)
        ->assertJsonPath('data.lines.0.priced_by', 'promotion')
        ->assertJsonPath('data.lines.0.cashback_laari', 330)
        ->assertJsonPath('data.lines.1.priced_by', 'promotion')
        ->assertJsonPath('data.lines.1.cashback_laari', 1070)
        ->assertJsonPath('data.lines.1.fee_laari', 179);
});

it('returns lined promo headroom when the lined transaction is reversed', function () {
    // Cap 1,400: a veggies credit consumes 1,200 of it...
    $promo = capLinesPromo(1400);

    $first = $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => 'veggies', 'amount_laari' => 20000],
    ]))->assertCreated();

    Transaction::query()->whereKey($first->json('data.id'))->sole()
        ->forceFill(['state' => 'reversed'])->saveQuietly();

    // ...but its reversal returns the headroom: the next identical credit
    // earns the full promo grant again.
    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => 'veggies', 'amount_laari' => 20000],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.priced_by', 'promotion')
        ->assertJsonPath('data.lines.0.cashback_laari', 1200);

    expect(Transaction::query()->latest('id')->first()->promotion_id)->toBe($promo->id);
});

it('lets a lined credit consume cap a later SINGLE-RATE credit must respect, and vice versa', function () {
    // Cross-path cap accounting: lined and unlined credits share one
    // headroom pool. Cap 1,400.
    capLinesPromo(1400);

    // Lined credit consumes 1,200 (veggies promo line).
    $this->postJson('/api/merchant/credits', capLinesPayload([
        ['category' => 'veggies', 'amount_laari' => 20000],
    ]))->assertCreated();

    // Single-rate credit of 25,000: promo normal intdiv(25,000·600+9,999,
    // 10,000) = 1,500 > remaining 200 → clip 200 < standing
    // intdiv(25,000·500+9,999, 10,000) = 1,250 → the single-rate FLOOR
    // takes the standing terms, unstamped.
    $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'SR-1',
        'eligible_amount' => 25000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.cashback_laari', 1250);

    expect(Transaction::query()->latest('id')->first()->promotion_id)->toBeNull();
});
