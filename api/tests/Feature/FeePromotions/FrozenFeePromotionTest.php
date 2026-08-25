<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\AmendmentService;
use App\Domain\Cashback\LineInput;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Models\AdminUser;
use App\Models\MerchantProductCategory;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeePromotions\FeePromotionFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * FROZEN AT CREATION — the law `rate_bp`, `fee_bp` and the GST stamp already
 * live by, extended to fee promotions.
 *
 * Ending a promotion, re-rating it or moving its window must price NEW sales
 * only. A merchant holds a receipt for what they were quoted, and a
 * settlement they have already paid must not move by a laari because a
 * marketing campaign finished.
 *
 * The assertion is deliberately BLUNT: the whole transaction row, its lines,
 * its settlement and the ledger entries are captured before the promotion is
 * torn down and compared attribute for attribute afterwards.
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->base = CarbonImmutable::parse('2026-09-05T06:00:00Z');
    Carbon::setTestNow($this->base);

    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(30), 0);

    $this->merchant = FeePromotionFixture::merchant($this->base->subYear());
    $this->owner = FeePromotionFixture::owner($this->merchant);
    $this->customer = FeePromotionFixture::customer();

    // A rate category, so a lined basket can carry two distinct lines — the
    // line parser refuses two default-bucket lines on one credit.
    $this->veggies = MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id,
        'slug' => 'veggies',
        'name_en' => 'Veggies',
        'mode' => 'rate',
        'rate_bp' => 200,
        'active' => true,
        'sort' => 1,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Everything about a settled sale that could conceivably move. */
function frozenSnapshot(int $transactionId): array
{
    return [
        'transaction' => Transaction::query()->findOrFail($transactionId)->getAttributes(),
        'lines' => TransactionLine::query()
            ->where('transaction_id', $transactionId)
            ->orderBy('sort')
            ->get()
            ->map->getAttributes()
            ->all(),
        'settlement' => Settlement::query()->first()?->getAttributes(),
        'ledger' => DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
    ];
}

/** The promotion torn down and replaced with a different one, mid-life. */
function tearDownAndReplace(CarbonImmutable $base): void
{
    FeePromotionFixture::endAll();

    FeePromotionFixture::write([
        'wide_enabled' => true,
        'wide_from' => $base->addDays(10)->utc(),
        'wide_to' => $base->addDays(40)->utc(),
        // A DIFFERENT fee, on a DIFFERENT window. If anything downstream read
        // the live settings instead of the row's own stamp, this is the
        // number that would show up in the assertions above.
        'wide_fee_bp' => 25,
        'wide_banner_en' => 'A different offer entirely.',
        'wide_banner_dv' => 'މުޅިން އެހެން އޮފަރެއް.',
    ]);
}

it('leaves a settled sale, its settlement and the ledger byte-identical when the promotion ends and its fee changes', function (): void {
    $sale = FeePromotionFixture::credit($this->merchant, $this->owner, $this->customer, 100_000, $this->base->subHour());

    Carbon::setTestNow($this->base->addDays(4));
    app(TransitionService::class)->makePayable($sale, Actor::system());

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($this->merchant->refresh()))->refresh();

    $allocator = app(SettlementAllocator::class);
    $payment = $allocator->recordBankPayment($settlement, Laari::of($settlement->amount_due_laari), 'BML-'.Str::upper(Str::random(10)));
    $allocator->matchPayment($payment, AdminUser::factory()->create());

    $before = frozenSnapshot($sale->id);

    // The sale was free, and the row says so and says what it displaced.
    expect($before['transaction']['fee_bp'])->toBe(0)
        ->and($before['transaction']['fee_promo_kind'])->toBe('platform_wide')
        ->and((int) $before['transaction']['list_fee_bp'])->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and((int) $before['transaction']['fee_forgone_laari'])->toBe(750)
        ->and((int) $before['settlement']['amount_due_laari'])->toBe(2_000);

    // ── The campaign ends and a different one replaces it ────────────────
    Carbon::setTestNow($this->base->addDays(20));
    tearDownAndReplace($this->base);

    expect(frozenSnapshot($sale->id))->toBe($before);
});

it('prices the NEXT sale under the new terms, so the assertion above is not vacuous', function (): void {
    $free = FeePromotionFixture::credit($this->merchant, $this->owner, $this->customer, 100_000, $this->base->subHour());

    expect($free->fee_bp)->toBe(0);

    Carbon::setTestNow($this->base->addDays(20));
    tearDownAndReplace($this->base);

    $later = FeePromotionFixture::credit(
        $this->merchant,
        $this->owner,
        $this->customer,
        100_000,
        CarbonImmutable::now('UTC')->subHour(),
    );

    // Inside the REPLACEMENT window, at the REPLACEMENT fee.
    expect($later->fee_bp)->toBe(25)
        ->and($later->fee_laari)->toBe(250)
        ->and($later->fee_promo_fee_bp)->toBe(25)
        ->and($later->fee_forgone_laari)->toBe(500)
        // And the first sale is still free.
        ->and($free->refresh()->fee_bp)->toBe(0);
});

it('re-prices an AMENDED lined sale under the promotion it was rung up under, not the one running today', function (): void {
    // A lined credit, priced free, corrected after the promotion ended. The
    // amount changes; the terms do not. The unlined path gets this for free
    // (it reuses the row's own fee_bp); the lined path re-resolves per line
    // and is handed the row's stamped relief so it cannot pick up today's.
    $sale = FeePromotionFixture::credit(
        $this->merchant,
        $this->owner,
        $this->customer,
        100_000,
        $this->base->subHour(),
        lines: [new LineInput($this->veggies, 60_000), new LineInput(null, 40_000)],
    );

    expect($sale->fee_bp)->toBe(0)
        ->and($sale->fee_forgone_laari)->toBe(750);

    Carbon::setTestNow($this->base->addHours(2));
    tearDownAndReplace($this->base);

    $amended = app(AmendmentService::class)->amend(
        $sale->refresh(),
        Actor::merchantUser($this->owner->id),
        Laari::of(50_000),
        null,
        [['category' => 'veggies', 'amount_laari' => 30_000], ['amount_laari' => 20_000]],
    );

    expect($amended->fee_bp)->toBe(0)
        ->and($amended->fee_laari)->toBe(0)
        ->and($amended->fee_promo_kind)->toBe('platform_wide')
        ->and($amended->fee_promo_fee_bp)->toBe(0)
        // The MONEY moves with the amount — half the sale gives up half the
        // fee — while the TERMS stay exactly where they were.
        ->and($amended->fee_forgone_laari)->toBe(375)
        ->and($amended->cashback_laari)->toBe(1_000);

    $lines = TransactionLine::query()->where('transaction_id', $sale->id)->orderBy('sort')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->sum('fee_forgone_laari'))->toBe($amended->fee_forgone_laari)
        ->and((int) $lines[0]->fee_bp)->toBe(0)
        ->and((int) $lines[0]->list_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP);
});

it('amends a sale that carried NO promotion without inventing one', function (): void {
    // The control for the branch above: with the promotion off at the time of
    // the sale and ON at the time of the correction, the correction must not
    // pick it up.
    FeePromotionFixture::endAll();

    $sale = FeePromotionFixture::credit(
        $this->merchant,
        $this->owner,
        $this->customer,
        100_000,
        $this->base->subHour(),
        lines: [new LineInput($this->veggies, 60_000), new LineInput(null, 40_000)],
    );

    expect($sale->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_promo_kind)->toBeNull();

    Carbon::setTestNow($this->base->addHours(2));
    FeePromotionFixture::platformWide($this->base->subDay(), $this->base->addDays(30), 0);

    $amended = app(AmendmentService::class)->amend(
        $sale->refresh(),
        Actor::merchantUser($this->owner->id),
        Laari::of(50_000),
        null,
        [['category' => 'veggies', 'amount_laari' => 30_000], ['amount_laari' => 20_000]],
    );

    expect($amended->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($amended->fee_laari)->toBe(375)
        ->and($amended->fee_promo_kind)->toBeNull()
        ->and($amended->fee_forgone_laari)->toBe(0);
});

it('pins the header stamp shape when a promotion reduced only a LINE', function (): void {
    // The one shape that reads like a contradiction and is not, pinned so it
    // is a decision rather than an accident.
    //
    // The header's `fee_bp` is the BASE-RATE SNAPSHOT (§4: per-line truth
    // lives on the lines), and `list_fee_bp` is its matched pair — the
    // before-price of that same snapshot, costed on the same rate. So a sale
    // whose base rate the promotion did not beat, but one of whose CATEGORY
    // lines it did, carries the kind, the offered fee and the forgone money
    // on the header while `list_fee_bp` stays null. The whole before/after
    // story for that line lives on the LINE.
    //
    // AmendmentService is why the pairing may not be loosened: correcting a
    // lined sale back into an unlined one re-prices from exactly those two
    // header columns, and a `list_fee_bp` borrowed from some line would cost
    // the "before" figure on a rate the "after" figure was never costed on.
    Carbon::setTestNow($this->base->addSeconds(1));

    // A promotion at EXACTLY the standing rate's tier fee: the base rate is
    // untouched, the 2.00%-category line... is the same rate, so give the
    // category a dearer band of its own.
    $lux = MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id,
        'slug' => 'lux',
        'name_en' => 'Lux',
        'mode' => 'rate',
        'rate_bp' => 500,
        'active' => true,
        'sort' => 2,
    ]);

    FeePromotionFixture::platformWide(
        $this->base->subDay(),
        $this->base->addDays(30),
        FeePromotionFixture::TIER_FEE_BP,
    );

    $sale = FeePromotionFixture::credit(
        $this->merchant,
        $this->owner,
        $this->customer,
        100_000,
        CarbonImmutable::now('UTC'),
        lines: [new LineInput($lux, 50_000), new LineInput(null, 50_000)],
    );

    expect($sale->fee_promo_kind)->toBe('platform_wide')
        ->and($sale->fee_promo_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        // NULL, and correctly so: the base rate's own 0.75% tier fee was not
        // beaten by a 0.75% offer.
        ->and($sale->list_fee_bp)->toBeNull()
        // The money still says a promotion paid for part of this sale.
        ->and($sale->fee_forgone_laari)->toBe(125);

    $lines = TransactionLine::query()
        ->where('transaction_id', $sale->id)
        ->orderBy('sort')
        ->get();

    // The line the promotion DID reduce carries its own before-price, which
    // is where the whole story lives.
    expect((int) $lines[0]->fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and((int) $lines[0]->list_fee_bp)->toBe(100)
        ->and((int) $lines[0]->fee_forgone_laari)->toBe(125)
        ->and($lines[1]->list_fee_bp)->toBeNull()
        ->and((int) $lines[1]->fee_forgone_laari)->toBe(0);
});

it('strips the promotion stamp from a sale an amendment drops below the minimum', function (): void {
    // A zeroed AMENDED row has to be byte-identical to a zeroed CREATED one
    // (CreditRecorder::NO_FEE_PROMOTION). It was charged nothing, so it gave
    // up nothing and no promotion priced it — and leaving the kind behind
    // would put the row inside `transactions_fee_promo_kind_index`, the
    // partial index built to answer "show me every sale a promotion paid
    // for", carrying a cost of zero.
    $sale = FeePromotionFixture::credit(
        $this->merchant,
        $this->owner,
        $this->customer,
        100_000,
        $this->base->subHour(),
    );

    expect($sale->fee_promo_kind)->toBe('platform_wide')
        ->and((int) $sale->list_fee_bp)->toBe(FeePromotionFixture::TIER_FEE_BP)
        ->and($sale->fee_forgone_laari)->toBe(750);

    // Below the store's 50.00 minimum: the sale earns nothing and costs
    // nothing.
    $amended = app(AmendmentService::class)->amend(
        $sale->refresh(),
        Actor::merchantUser($this->owner->id),
        Laari::of(100),
        null,
        null,
    );

    expect($amended->reason_code)->toBe('below_minimum')
        ->and($amended->fee_laari)->toBe(0)
        ->and($amended->fee_promo_kind)->toBeNull()
        ->and($amended->fee_promo_fee_bp)->toBeNull()
        ->and($amended->list_fee_bp)->toBeNull()
        ->and($amended->fee_forgone_laari)->toBe(0);
});
