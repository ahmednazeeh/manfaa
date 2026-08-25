<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Standing\Reconciler;
use App\Domain\Standing\WriteOffService;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * THE LEDGER SPLIT (§8, C4): net fee → 4100 Platform Fee Revenue, GST →
 * 2300 Fee Tax Payable. A liability to MIRA is not income, and a platform
 * that books its own tax collections as revenue overstates every month it
 * ever files.
 *
 * The split happens in the PRICER, not in Postings: after FeeTax::split,
 * `fee_laari` is always the net and `fee_gst_laari` always the tax, so the
 * §8 catalogue — which already credits 4100 and 2300 separately from those
 * two integers — is correct under both treatments with no change at all.
 * These tests are what make that claim checkable rather than convenient.
 *
 * The nightly Reconciler must stay green through every path, because it
 * derives revenue from `Σ fee_laari` and the receivable from
 * `Σ (cashback + fee + gst)` — the same two columns the pricer writes.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

    $this->seed(LedgerAccountSeeder::class);

    // Off: this file is about the §8 catalogue, not the incentive.
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $this->admin = AdminUser::factory()->create();
    $this->balances = new Balances;
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

function gstLedgerCredit(string $invoice, int $eligibleLaari = 100_000): Transaction
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

it('splits the accrual: net fee to 4100, GST to 2300, receivable for the sum', function (string $treatment, int $net, int $gst) {
    GstFixture::enable(800, $treatment);

    gstLedgerCredit('INV-ACCRUE');

    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(2_000 + $net + $gst)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2_000)
        // Revenue is the NET fee — the tax was never ours.
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe($net)
        // A liability to MIRA, standing on its own account.
        ->and($this->balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe($gst)
        ->and(app(Reconciler::class)->run()->status)->toBe('ok');
})->with([
    'on top' => ['on_top', 750, 60],
    'inclusive' => ['inclusive', 694, 56],
]);

it('stays balanced and reconciled through settle, reverse and write-off', function (string $treatment, int $net, int $gst) {
    GstFixture::enable(800, $treatment);

    $transitions = app(TransitionService::class);
    $due = 2_000 + $net + $gst;

    // ── SETTLED ──────────────────────────────────────────────────────────
    $settled = gstLedgerCredit('INV-SETTLED');
    $transitions->makePayable($settled, Actor::system());

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($this->merchant))->refresh();

    expect($settlement->fee_total_laari)->toBe($net)
        ->and($settlement->fee_gst_total_laari)->toBe($gst)
        ->and($settlement->amount_due_laari)->toBe($due);

    $allocator = app(SettlementAllocator::class);
    $allocator->matchPayment(
        $allocator->recordBankPayment($settlement->refresh(), Laari::of($due), 'BML-GST-1'),
        $this->admin,
    );

    expect($settlement->refresh()->state)->toBe(SettlementState::Settled)
        // Cash in, receivable cleared; revenue and the tax payable stay put
        // — settlement collects a debt, it does not re-earn one.
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe($net)
        ->and($this->balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe($gst)
        ->and(app(Reconciler::class)->run()->status)->toBe('ok');

    // ── REVERSED ─────────────────────────────────────────────────────────
    // A fresh sale, cancelled before it confirms: the accrual comes off at
    // exactly the integers it went on at, tax leg included.
    $reversed = gstLedgerCredit('INV-REVERSED');

    app(ReversalService::class)->reverse(
        $reversed,
        Actor::merchantUser($this->user->id),
        'customer_refund',
        CarbonImmutable::now('UTC'),
    );

    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe($net)
        ->and($this->balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe($gst)
        ->and(app(Reconciler::class)->run()->status)->toBe('ok');

    // ── WRITTEN OFF ──────────────────────────────────────────────────────
    // A third sale left unpaid past the 90-day cutoff: the platform's own
    // margin — fee AND the tax on it — becomes bad debt (§14 keeps the GST
    // treatment there until an accountant rules otherwise).
    $stale = gstLedgerCredit('INV-STALE');
    $transitions->makePayable($stale, Actor::system());

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00')->addDays(120));

    expect(app(WriteOffService::class)->run())->toBe(1)
        ->and($this->balances->naturalBalance(AccountCode::BadDebtExpense))->toBe($net + $gst)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        // Write-off charges bad debt; it never claws revenue back — so the
        // settled sale's net fee and the written-off one's are BOTH still
        // in 4100, and the tax on both is still owed to MIRA.
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(2 * $net)
        ->and($this->balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe(2 * $gst)
        ->and(app(Reconciler::class)->run()->status)->toBe('ok');
})->with([
    'on top' => ['on_top', 750, 60],
    'inclusive' => ['inclusive', 694, 56],
]);

it('keeps every journal balanced with GST on — debits equal credits, always', function (string $treatment) {
    GstFixture::enable(800, $treatment);

    $transaction = gstLedgerCredit('INV-BALANCE');
    app(TransitionService::class)->makePayable($transaction, Actor::system());

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($this->merchant))->refresh();

    $allocator = app(SettlementAllocator::class);
    $allocator->matchPayment(
        $allocator->recordBankPayment($settlement, Laari::of($settlement->amount_due_laari), 'BML-GST-2'),
        $this->admin,
    );

    $run = app(Reconciler::class)->run();

    expect($run->status)->toBe('ok')
        ->and($run->issues)->toBeNull()
        ->and($run->journals_checked)->toBeGreaterThan(0);
})->with(['on_top', 'inclusive']);

it('derives 2300 from the rows and catches drift the ledger alone would hide', function (string $treatment, int $net, int $gst) {
    GstFixture::enable(800, $treatment);

    gstLedgerCredit('INV-DERIVED');

    $run = app(Reconciler::class)->run();

    // The fourth invariant: what the rows say was charged as tax, against
    // what the ledger says is owed to MIRA.
    expect($run->status)->toBe('ok')
        ->and($run->totals['fee_tax'])->toBe(['derived_laari' => $gst, 'ledger_laari' => $gst])
        ->and($this->balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe($gst)
        ->and($run->totals['revenue']['derived_laari'])->toBe($net);

    // A BALANCED journal that credits the tax payable out of nowhere — the
    // shape every other check is blind to, because journal balance and the
    // three old derivations all stay perfectly happy.
    app(LedgerPoster::class)->post('manual', 0, 'Rogue tax credit', [
        ['account' => AccountCode::BadDebtExpense, 'debit_laari' => 5_000],
        ['account' => AccountCode::FeeTaxPayable, 'credit_laari' => 5_000],
    ]);

    $drifted = app(Reconciler::class)->run();

    expect($drifted->status)->toBe('divergent')
        ->and($drifted->totals['fee_tax'])->toBe([
            'derived_laari' => $gst,
            'ledger_laari' => $gst + 5_000,
        ])
        ->and(collect($drifted->issues)->firstWhere('account', 'fee_tax'))->toMatchArray([
            'kind' => 'balance_mismatch',
            'derived_laari' => $gst,
            'ledger_laari' => $gst + 5_000,
        ]);
})->with([
    'on top' => ['on_top', 750, 60],
    'inclusive' => ['inclusive', 694, 56],
]);
