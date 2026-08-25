<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Tax\FeeTax;
use App\Domain\Tax\FeeTreatment;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * GST PRICING, both treatments, at the line and at the header.
 *
 * THE INVARIANT UNDER TEST. Whatever the treatment, `fee_laari` is Manfaa's
 * NET revenue, `fee_gst_laari` is the tax owed to MIRA, and the merchant
 * owes cashback + fee + GST. The treatments differ only in what they do to
 * the fee the pricer produced:
 *
 *   on_top     the fee is untouched and the tax is ADDED — the merchant owes
 *              more, our revenue is unchanged.
 *   inclusive  the tax is CARVED OUT of the fee — the merchant owes exactly
 *              what they owed before, and our revenue drops by the tax.
 *
 * HAND DERIVATION (100,000 eligible, §4 200bp cashback / 75bp fee, GST 800bp):
 *
 *   cashback = intdiv(100,000·200 + 9,999, 10,000)          = 2,000
 *   fee      = intdiv(100,000·75  + 9,999, 10,000)          =   750
 *
 *   on_top    gst = intdiv(750·800 + 9,999, 10,000)         =    60
 *             net = 750                     due = 2,000+750+60 = 2,810
 *   inclusive gst = intdiv(750·800 + 10,799, 10,800)        =    56
 *             net = 750 − 56 = 694          due = 2,000+694+56 = 2,750
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

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
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Three distinct buckets, all priced at the standing 200bp / 75bp — two
 * merchant categories and the default one, because a line set may name each
 * bucket only once. Same rate throughout, so the only thing under test is
 * WHERE the rounding happens.
 *
 * @return list<array{category: string|null, amount_laari: int}>
 */
function gstThreeBuckets(): array
{
    foreach ([['dry', 30_000], ['chilled', 25_000]] as [$slug, $ignored]) {
        MerchantProductCategory::query()->firstOrCreate(
            ['merchant_id' => test()->merchant->id, 'slug' => $slug],
            ['name_en' => ucfirst($slug), 'mode' => 'rate', 'rate_bp' => 200, 'active' => true, 'sort' => 1],
        );
    }

    return [
        ['category' => 'dry', 'amount_laari' => 30_000],
        ['category' => 'chilled', 'amount_laari' => 25_000],
        ['category' => null, 'amount_laari' => 45_000],
    ];
}

function gstCredit(int $eligibleLaari = 100_000, string $invoice = 'INV-1001'): Transaction
{
    return app(ManualCreditService::class)->credit(
        test()->merchant,
        test()->user,
        '482917',
        $invoice,
        Laari::of($eligibleLaari),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    )->refresh();
}

it('is the identity while the switch is off — today\'s pricing, byte for byte', function () {
    $transaction = gstCredit();

    expect($transaction->cashback_laari)->toBe(2_000)
        ->and($transaction->fee_laari)->toBe(750)
        ->and($transaction->fee_gst_laari)->toBe(0)
        // Stamped anyway, and the stamp says "no tax applied" — which is
        // exactly what re-pricing this row from its own stamp reproduces.
        ->and($transaction->fee_gst_bp)->toBe(0)
        ->and($transaction->fee_treatment)->toBe(FeeTreatment::OnTop)
        ->and($transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari)->toBe(2_750);
});

it('ADDS the tax under on_top: the merchant owes more, our fee income is unchanged', function () {
    GstFixture::enable(800, 'on_top');

    $transaction = gstCredit();

    expect($transaction->cashback_laari)->toBe(2_000)
        ->and($transaction->fee_laari)->toBe(750)
        ->and($transaction->fee_gst_laari)->toBe(60)
        ->and($transaction->fee_gst_bp)->toBe(800)
        ->and($transaction->fee_treatment)->toBe(FeeTreatment::OnTop)
        // The whole point of on_top: 2,750 becomes 2,810.
        ->and($transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari)->toBe(2_810);
});

it('CARVES the tax out under inclusive: the merchant owes the same, our fee income drops', function () {
    GstFixture::enable(800, 'inclusive');

    $transaction = gstCredit();

    expect($transaction->cashback_laari)->toBe(2_000)
        // 750 of quoted fee is now 694 of revenue plus 56 of tax.
        ->and($transaction->fee_laari)->toBe(694)
        ->and($transaction->fee_gst_laari)->toBe(56)
        ->and($transaction->fee_laari + $transaction->fee_gst_laari)->toBe(750)
        ->and($transaction->fee_gst_bp)->toBe(800)
        ->and($transaction->fee_treatment)->toBe(FeeTreatment::Inclusive)
        // Unchanged — that is what "inclusive" promises the merchant.
        ->and($transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari)->toBe(2_750);
});

it('rounds the tax with the §4 ceiling, the same way the fee itself is rounded', function () {
    $onTop = FeeTax::of(800, 'on_top');
    $inclusive = FeeTax::of(800, 'inclusive');

    // A fee of 1 laari still owes tax: rounding a tax DOWN to nothing is
    // how a platform quietly under-remits.
    expect($onTop->split(1))->toBe([1, 1])
        ->and($inclusive->split(1))->toBe([0, 1])
        ->and($onTop->split(750))->toBe([750, 60])
        ->and($inclusive->split(750))->toBe([694, 56])
        // Nothing in, nothing out.
        ->and($onTop->split(0))->toBe([0, 0])
        ->and($inclusive->split(0))->toBe([0, 0])
        // Zero bp is the identity in BOTH directions — the property that
        // makes every historical row reproduce itself from its own stamp.
        ->and(FeeTax::of(0, 'on_top')->split(750))->toBe([750, 0])
        ->and(FeeTax::of(0, 'inclusive')->split(750))->toBe([750, 0]);

    // Inclusive is exactly the owner's "fee − round(fee·10000/10800)".
    foreach ([1, 7, 55, 188, 337, 750, 12_345] as $fee) {
        [$net, $gst] = $inclusive->split($fee);

        expect($net)->toBe(intdiv($fee * 10000, 10800))
            ->and($net + $gst)->toBe($fee);
    }
});

it('taxes a LINED credit per line, so the header equals the sum of its own lines', function () {
    GstFixture::enable(800, 'on_top');

    // Three lines, all at the standing 200bp/75bp — chosen because the
    // per-line fees (225 / 188 / 338 = 751) do NOT equal a fee computed on
    // the 100,000 aggregate (750), and neither does the tax: per line
    // 18 + 16 + 28 = 62, on the header 61. Rounding at the line is the only
    // way the two can agree.
    expect(intdiv(30_000 * 75 + 9999, 10000))->toBe(225)
        ->and(intdiv(25_000 * 75 + 9999, 10000))->toBe(188)
        ->and(intdiv(45_000 * 75 + 9999, 10000))->toBe(338)
        ->and(intdiv(225 * 800 + 9999, 10000))->toBe(18)
        ->and(intdiv(188 * 800 + 9999, 10000))->toBe(16)
        ->and(intdiv(338 * 800 + 9999, 10000))->toBe(28);

    $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-LINED',
            'eligible_amount' => 100_000,
            'occurred_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(),
            'lines' => gstThreeBuckets(),
        ])
        ->assertCreated();

    $transaction = Transaction::query()->sole();
    $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('sort')->get();

    expect($lines)->toHaveCount(3)
        ->and($lines->pluck('fee_laari')->all())->toBe([225, 188, 338])
        ->and($lines->pluck('fee_gst_laari')->all())->toBe([18, 16, 28])
        ->and($lines->pluck('fee_gst_bp')->unique()->all())->toBe([800])
        // THE HEADER IS THE SUM OF THE STORED LINES — never a second,
        // differently rounded computation over the aggregate.
        ->and($transaction->fee_laari)->toBe((int) $lines->sum('fee_laari'))
        ->and($transaction->fee_gst_laari)->toBe((int) $lines->sum('fee_gst_laari'))
        ->and($transaction->fee_laari)->toBe(751)
        ->and($transaction->fee_gst_laari)->toBe(62);
});

it('taxes a lined credit per line under inclusive too, with the fee unchanged in total', function () {
    GstFixture::enable(800, 'inclusive');

    $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-LINED-INC',
            'eligible_amount' => 100_000,
            'occurred_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(),
            'lines' => gstThreeBuckets(),
        ])
        ->assertCreated();

    $transaction = Transaction::query()->sole();
    $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('sort')->get();

    // 225 → 208+17, 188 → 174+14, 338 → 312+26.
    expect($lines->pluck('fee_laari')->all())->toBe([208, 174, 312])
        ->and($lines->pluck('fee_gst_laari')->all())->toBe([17, 14, 26])
        ->and($transaction->fee_laari)->toBe((int) $lines->sum('fee_laari'))
        ->and($transaction->fee_gst_laari)->toBe((int) $lines->sum('fee_gst_laari'))
        // The quoted fee total is untouched: 695 + 56 = 751.
        ->and($transaction->fee_laari + $transaction->fee_gst_laari)->toBe(751);
});

it('leaves an excluded line and a zeroed row taxed at nothing, stamped all the same', function () {
    GstFixture::enable(800, 'on_top');

    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'tobacco', 'name_en' => 'Tobacco',
        'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);

    $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-EXCLUDED',
            'eligible_amount' => 100_000,
            'occurred_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(),
            'lines' => [
                ['category' => 'tobacco', 'amount_laari' => 40_000],
                ['category' => null, 'amount_laari' => 60_000],
            ],
        ])
        ->assertCreated();

    $lines = TransactionLine::query()->orderBy('sort')->get();

    // No fee on an excluded line, so no tax on it either — but the terms it
    // met are still evidenced, exactly as rate_bp is on a zeroed row.
    expect($lines[0]->fee_laari)->toBe(0)
        ->and($lines[0]->fee_gst_laari)->toBe(0)
        ->and($lines[0]->fee_gst_bp)->toBe(800)
        ->and($lines[1]->fee_laari)->toBe(450)
        ->and($lines[1]->fee_gst_laari)->toBe(36);

    // A BELOW-MINIMUM sale earns nothing and is taxed on nothing, while
    // still freezing the terms it failed against.
    $zeroed = gstCredit(1_000, 'INV-TINY');

    expect($zeroed->cashback_laari)->toBe(0)
        ->and($zeroed->fee_laari)->toBe(0)
        ->and($zeroed->fee_gst_laari)->toBe(0)
        ->and($zeroed->fee_gst_bp)->toBe(800)
        ->and($zeroed->reason_code)->toBe('below_minimum');
});

it('puts GST into the amount due under on_top and leaves it alone under inclusive', function () {
    $transitions = app(TransitionService::class);
    $builder = app(SettlementBuilder::class);

    // Batch one: no GST at all.
    $plain = gstCredit(100_000, 'INV-PLAIN');
    $transitions->makePayable($plain, Actor::system());
    $plainBatch = $builder->createDraft($this->merchant)->refresh();

    expect($plainBatch->fee_total_laari)->toBe(750)
        ->and($plainBatch->fee_gst_total_laari)->toBe(0)
        ->and($plainBatch->amount_due_laari)->toBe(2_750);

    $builder->cancel($plainBatch);

    // Batch two: the same sale priced ON TOP — the bill goes UP by the tax.
    GstFixture::enable(800, 'on_top');
    $onTop = gstCredit(100_000, 'INV-ONTOP');
    $transitions->makePayable($onTop, Actor::system());
    $onTopBatch = $builder->createDraft($this->merchant)->refresh();

    expect($onTopBatch->fee_total_laari)->toBe(750 + 750)
        ->and($onTopBatch->fee_gst_total_laari)->toBe(60)
        // The untaxed line still owes 2,750; the taxed one owes 2,810.
        ->and($onTopBatch->amount_due_laari)->toBe(2_750 + 2_810);

    $builder->cancel($onTopBatch);

    // Batch three: INCLUSIVE — the tax appears as its own figure without
    // moving what the merchant transfers.
    GstFixture::enable(800, 'inclusive');
    $inclusive = gstCredit(100_000, 'INV-INC');
    $transitions->makePayable($inclusive, Actor::system());
    $inclusiveBatch = $builder->createDraft($this->merchant)->refresh();

    expect($inclusiveBatch->fee_gst_total_laari)->toBe(60 + 56)
        ->and($inclusiveBatch->amount_due_laari)->toBe(2_750 + 2_810 + 2_750);
});
