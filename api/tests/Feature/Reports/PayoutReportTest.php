<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Payout\ApprovalService;
use App\Domain\Payout\BankFileExporter;
use App\Domain\Payout\PayoutBatchBuilder;
use App\Domain\Payout\PayoutItemSettler;
use App\Domain\Payout\PayoutItemState;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

    $this->seed(LedgerAccountSeeder::class);

    $this->admin = AdminUser::factory()->create();
    $this->merchant = Merchant::factory()->create([
        'name' => 'Sea House Cafe',
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->creditor = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A credit walked all the way to confirmed through the real services. */
function payoutConfirm(Customer $customer, int $eligibleLaari): Transaction
{
    $transitions = app(TransitionService::class);

    $transaction = app(ManualCreditService::class)->credit(
        test()->merchant,
        test()->creditor,
        $customer->customer_code,
        'INV-'.Str::upper(Str::random(10)),
        Laari::of($eligibleLaari),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    );

    $transitions->makePayable($transaction, Actor::system());
    $transitions->confirm($transaction, Actor::system());

    return $transaction->refresh();
}

function augustPayoutReport(?int $merchantId = null): PayoutReport
{
    return new PayoutReport(ReportPeriod::of('2026-08-01', '2026-08-31'), $merchantId);
}

it('ties transactions, payout items and batches — with a failure and a wallet withdrawal in the period', function () {
    $paidCustomer = ReportFixture::customer('Aminath Shifa');
    $failedCustomer = ReportFixture::customer('Hawwa Latheefa');
    $withdrawer = ReportFixture::customer('Mariyam Zulfa');

    // 500,000 at 200bp = 10,000 laari, exactly the MVR 100 minimum.
    payoutConfirm($paidCustomer, 500_000);
    payoutConfirm($paidCustomer, 250_000);
    payoutConfirm($failedCustomer, 600_000);

    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());

    $settler = app(PayoutItemSettler::class);
    $paidItem = $batch->items()->where('customer_id', $paidCustomer->id)->sole();
    $failedItem = $batch->items()->where('customer_id', $failedCustomer->id)->sole();

    $settler->settleOne($paidItem, 'BML-TRX-1');
    $settler->failOne($failedItem, 'Account closed');

    // A wallet withdrawal, the other road out of the platform.
    $wallet = app(WalletService::class);
    $wallet->credit($withdrawer, 40_000, 'refund', description: 'Order refund');
    $withdrawal = $wallet->requestWithdrawal($withdrawer, 25_000);
    $withdrawal->forceFill(['state' => 'sent', 'processed_at' => CarbonImmutable::now('UTC'), 'trx_id' => 'BML-W-1'])->save();

    // And one that went back to the wallet — terminal, and NOT a failure to
    // retry (migration 2026_08_19_070000).
    $returned = $wallet->requestWithdrawal($withdrawer, 5_000);
    $returned->forceFill([
        'state' => 'refunded',
        'processed_at' => CarbonImmutable::now('UTC'),
        'failure_reason' => 'Refused by the bank; balance returned.',
    ])->save();

    $report = augustPayoutReport();

    $transactions = $report->sheet(PayoutReport::TRANSACTIONS);
    $payouts = $report->sheet(PayoutReport::PAYOUTS);
    $batches = $report->sheet(PayoutReport::BATCHES);
    $withdrawals = $report->sheet(PayoutReport::WITHDRAWALS);

    // 10,000 + 5,000 for the paid customer; the failed customer's 12,000
    // was unlinked and never paid.
    expect($transactions->count())->toBe(2)
        ->and($transactions->sum('cashback_laari'))->toBe(15_000);

    // THE TIE.
    expect($payouts->sum('paid_laari'))->toBe($transactions->sum('cashback_laari'))
        ->and($batches->sum('paid_laari'))->toBe($transactions->sum('cashback_laari'))
        ->and($report->summary()['ties'])->toBe([
            'transactions_cashback_laari' => 15_000,
            'payout_items_paid_laari' => 15_000,
            'batches_paid_laari' => 15_000,
        ]);

    // The failed item is still ON the sheet — with its reason, its own
    // amount, and nothing attributed to the period.
    $statusIndex = $payouts->indexOf('status');
    $failedRow = collect($payouts->rows())->firstWhere($statusIndex, 'failed');

    expect($payouts->count())->toBe(2)
        ->and($failedRow[$payouts->indexOf('amount_laari')])->toBe(12_000)
        ->and($failedRow[$payouts->indexOf('paid_laari')])->toBe(0)
        ->and($failedRow[$payouts->indexOf('transaction_count')])->toBe(0)
        ->and($failedRow[$payouts->indexOf('failure_reason')])->toBe('Account closed')
        // Masked name, last four of the account only.
        ->and($failedRow[$payouts->indexOf('customer')])->toBe('Haw*** Lat***')
        ->and($failedRow[$payouts->indexOf('account')])->toStartWith('****');

    // The batch's own total still says what the bank was asked for.
    expect($batches->count())->toBe(1)
        ->and($batches->sum('total_laari'))->toBe(27_000)
        ->and($batches->rows()[0][$batches->indexOf('status')])->toBe('partially failed');

    // Wallet withdrawals, split by what happened to them.
    expect($withdrawals->count())->toBe(2)
        ->and($withdrawals->sum('amount_laari'))->toBe(30_000);

    $summary = $report->summary();

    expect($summary['wallet_withdrawals']['amount_laari'])->toBe(30_000);

    $paidRow = collect($withdrawals->rows())->firstWhere($withdrawals->indexOf('status'), 'paid');
    $returnedRow = collect($withdrawals->rows())->firstWhere($withdrawals->indexOf('status'), 'refunded to wallet');

    expect($paidRow[$withdrawals->indexOf('amount_laari')])->toBe(25_000)
        ->and($returnedRow[$withdrawals->indexOf('amount_laari')])->toBe(5_000)
        ->and($returnedRow[$withdrawals->indexOf('failure_reason')])->toBe('Refused by the bank; balance returned.');

    // Summary first, then the four detail sheets.
    expect(array_map(fn ($each) => $each->title, $report->sheets()))->toBe([
        PayoutReport::SUMMARY,
        PayoutReport::TRANSACTIONS,
        PayoutReport::PAYOUTS,
        PayoutReport::BATCHES,
        PayoutReport::WITHDRAWALS,
    ]);
});

it('periodises on the paid date, not the date the reward was earned', function () {
    $customer = ReportFixture::customer();

    payoutConfirm($customer, 600_000);

    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());

    // The bank pays in SEPTEMBER, a fortnight after the sale.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-03T10:00:00+05:00'));
    app(PayoutItemSettler::class)->settleOne($batch->items()->sole(), 'BML-LATE');

    expect(augustPayoutReport()->sheet(PayoutReport::TRANSACTIONS)->count())->toBe(0);

    $september = new PayoutReport(ReportPeriod::of('2026-09-01', '2026-09-30'), null);

    expect($september->sheet(PayoutReport::TRANSACTIONS)->count())->toBe(1)
        ->and($september->sheet(PayoutReport::TRANSACTIONS)->sum('cashback_laari'))->toBe(12_000)
        ->and($september->sheet(PayoutReport::PAYOUTS)->sum('paid_laari'))->toBe(12_000)
        ->and($september->sheet(PayoutReport::BATCHES)->sum('paid_laari'))->toBe(12_000);
});

it('keeps the tie under a merchant filter, and leaves wallet withdrawals out of it', function () {
    $customer = ReportFixture::customer();

    payoutConfirm($customer, 600_000);

    // A second shop paying the same customer in the same run.
    $other = Merchant::factory()->create(['name' => 'Island Mart']);
    MerchantRate::factory()->for($other)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $otherUser = MerchantUser::factory()->for($other)->owner()->create();

    $transitions = app(TransitionService::class);
    $otherTransaction = app(ManualCreditService::class)->credit(
        $other,
        $otherUser,
        $customer->customer_code,
        'INV-OTHER-1',
        Laari::of(300_000),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    );
    $transitions->makePayable($otherTransaction, Actor::system());
    $transitions->confirm($otherTransaction, Actor::system());

    $wallet = app(WalletService::class);
    $wallet->credit($customer, 20_000, 'refund');
    $wallet->requestWithdrawal($customer, 10_000);

    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());
    app(PayoutItemSettler::class)->settleOne($batch->items()->sole(), 'BML-BOTH');

    // One item, 18,000 laari, funded by two shops.
    expect(PayoutItem::query()->sole()->amount_laari)->toBe(18_000);

    $filtered = augustPayoutReport($this->merchant->id);

    expect($filtered->sheet(PayoutReport::TRANSACTIONS)->sum('cashback_laari'))->toBe(12_000)
        // The item's own amount stays whole; only the attribution narrows.
        ->and($filtered->sheet(PayoutReport::PAYOUTS)->sum('amount_laari'))->toBe(18_000)
        ->and($filtered->sheet(PayoutReport::PAYOUTS)->sum('paid_laari'))->toBe(12_000)
        ->and($filtered->sheet(PayoutReport::BATCHES)->sum('paid_laari'))->toBe(12_000)
        // A customer's wallet balance belongs to no shop.
        ->and($filtered->sheet(PayoutReport::WITHDRAWALS)->count())->toBe(0);

    expect(augustPayoutReport()->sheet(PayoutReport::WITHDRAWALS)->count())->toBe(1);
});

it('counts a rebuilt batch once, though the cancelled draft kept the same reference', function () {
    // A draft is rebuilt by cancel + recreate, and the cancelled row KEEPS
    // its reference — the unique index spares only the live batches. Live
    // data carries three PB-20260816 rows for that reason. A Batches sheet
    // keyed on the reference string counted the same money once per
    // duplicate; this is the row that proves it does not.
    $customer = ReportFixture::customer();

    payoutConfirm($customer, 600_000);

    $builder = app(PayoutBatchBuilder::class);
    $cutoff = CarbonImmutable::now('UTC');

    $builder->cancelDraft($builder->buildDraft($cutoff, $this->admin));
    $builder->cancelDraft($builder->buildDraft($cutoff, $this->admin));

    $batch = $builder->buildDraft($cutoff, $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());
    app(PayoutItemSettler::class)->settleOne($batch->items()->sole(), 'BML-REBUILT');

    // Three rows, one reference, one of them alive.
    expect(PayoutBatch::query()->where('reference', $batch->reference)->count())->toBe(3);

    $report = augustPayoutReport();
    $batches = $report->sheet(PayoutReport::BATCHES);

    expect($batches->count())->toBe(1)
        ->and($batches->sum('paid_laari'))->toBe(12_000)
        ->and($batches->sum('total_laari'))->toBe(12_000)
        ->and($report->summary()['ties'])->toBe([
            'transactions_cashback_laari' => 12_000,
            'payout_items_paid_laari' => 12_000,
            'batches_paid_laari' => 12_000,
        ]);
});

it('counts money still waiting on bank details, so nothing is silently missing', function () {
    $ready = ReportFixture::customer();
    $noBank = Customer::factory()->create(['customer_code' => '777123']);

    payoutConfirm($ready, 600_000);
    payoutConfirm($noBank, 700_000);

    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());
    app(PayoutItemSettler::class)->settleOne($batch->items()->sole(), 'BML-READY');

    $batches = augustPayoutReport()->sheet(PayoutReport::BATCHES);

    expect($batches->sum('excluded_customer_count'))->toBe(1)
        ->and($batches->sum('excluded_total_laari'))->toBe(14_000)
        ->and($batches->sum('paid_laari'))->toBe(12_000);

    expect(PayoutBatch::query()->sole()->items()->where('state', PayoutItemState::Paid)->count())->toBe(1);
});

/*
 * WHERE THE PERIOD IS APPLIED. paidScope() filters on `max(created_at)` of a
 * transaction's PAID events, and pushes the window's LOWER bound down into
 * the grouping so the aggregate is not built over every paid event ever
 * recorded (migration 2026_08_25_120000 and the docblock there). The upper
 * bound is deliberately NOT pushed — dropping later events would promote an
 * earlier one to max and drag a re-paid sale back into a window it left.
 *
 * These three shapes are exactly what the two rules have to answer, and they
 * are the reason the asymmetry cannot be tidied away into symmetry.
 */
it('dates a sale by its LAST paid event, whichever side of the window the others fall', function () {
    $paid = TransactionState::Paid->value;

    $straddling = payoutConfirm(ReportFixture::customer('Straddling'), 500_000);
    $repaidLater = payoutConfirm(ReportFixture::customer('Repaid Later'), 500_000);
    $onlyBefore = payoutConfirm(ReportFixture::customer('Only Before'), 500_000);

    $event = function (Transaction $transaction, string $at) use ($paid): void {
        DB::table('transaction_events')->insert([
            'transaction_id' => $transaction->id,
            'from_state' => 'confirmed',
            'to_state' => $paid,
            'actor_type' => 'system',
            'created_at' => CarbonImmutable::parse($at),
        ]);
    };

    // Paid in July, paid again in August: August owns it, once.
    $event($straddling, '2026-07-20T06:00:00+00:00');
    $event($straddling, '2026-08-10T06:00:00+00:00');

    // Paid in August, paid again in September: SEPTEMBER owns it. Pushing the
    // upper bound into the grouping would make August's max the August event
    // and count it here — which is the bug the asymmetry prevents.
    $event($repaidLater, '2026-08-10T06:00:00+00:00');
    $event($repaidLater, '2026-09-05T06:00:00+00:00');

    // Paid before the window and never again: nobody's August.
    $event($onlyBefore, '2026-07-20T06:00:00+00:00');

    $totals = augustPayoutReport()->paidTotals();
    $daily = augustPayoutReport()->dailyPaid();

    expect($totals['count'])->toBe(1)
        ->and($totals['cashback_laari'])->toBe($straddling->cashback_laari)
        // Dated by the LATER event, and drawn on exactly one day.
        ->and($daily)->toBe(['2026-08-10' => $straddling->cashback_laari]);
});
