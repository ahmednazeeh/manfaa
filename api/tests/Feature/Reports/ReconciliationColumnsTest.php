<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Payout\ApprovalService;
use App\Domain\Payout\BankFileExporter;
use App\Domain\Payout\PayoutBatchBuilder;
use App\Domain\Payout\PayoutItemSettler;
use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\Sheet;
use App\Domain\Settlement\SettlementAllocator;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

/**
 * REFINEMENTS 4 and 5 (owner, 2026-08-24): the reconciliation columns.
 *
 * These are what a tax professional actually chases. A row saying "MVR 116.63
 * received" is not reconcilable; a row saying "MVR 116.63 received, bank
 * reference 90863673, payer INTERBRIDGE, matched automatically by
 * receipt_reference on 14 August" is.
 *
 *   4. PAYOUTS carry the bank's transfer reference (payout_items.bank_reference,
 *      populated on every live paid item), on the Payouts sheet and beside the
 *      sales it paid for on the Transactions sheet.
 *
 *   5. SETTLEMENTS carry BOTH bank references — what the merchant typed and
 *      what the bank called the money — aggregated across every matched
 *      payment, because one settlement can have several.
 */
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

function reconcilePeriod(): ReportPeriod
{
    return ReportPeriod::of('2026-08-01', '2026-08-31');
}

/** A credit walked to confirmed through the real services. */
function reconcileConfirm(Customer $customer, int $eligibleLaari): Transaction
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

/** The value of one column on the row matching a predicate. */
function cellWhere(Sheet $sheet, string $matchColumn, mixed $matchValue, string $column): mixed
{
    $matchIndex = $sheet->indexOf($matchColumn);
    $columnIndex = $sheet->indexOf($column);

    foreach ($sheet->rows() as $row) {
        if ($row[$matchIndex] === $matchValue) {
            return $row[$columnIndex];
        }
    }

    throw new RuntimeException(sprintf('No row where %s is %s.', $matchColumn, var_export($matchValue, true)));
}

// -------------------------------------- 4: the payout transfer reference

it('carries the bank transfer reference onto the payouts sheet and beside the sales it paid', function () {
    $customer = ReportFixture::customer('Aminath Shifa');

    reconcileConfirm($customer, 500_000);
    reconcileConfirm($customer, 250_000);

    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());

    $item = $batch->items()->where('customer_id', $customer->id)->sole();

    // The real settle path is what writes bank_reference — the same call the
    // bank result handler makes in production.
    app(PayoutItemSettler::class)->settleOne($item, 'BML-TRX-90863673');

    expect($item->refresh()->bank_reference)->toBe('BML-TRX-90863673');

    $report = new PayoutReport(reconcilePeriod());

    $payouts = $report->sheet(PayoutReport::PAYOUTS);
    $transactions = $report->sheet(PayoutReport::TRANSACTIONS);

    expect($payouts->column('bank_reference')?->label)->toBe('Transfer reference')
        ->and($payouts->rows()[0][$payouts->indexOf('bank_reference')])->toBe('BML-TRX-90863673');

    // One transfer paid two sales, so the reference repeats down the
    // Transactions sheet — which is exactly what somebody working back from
    // a bank statement line to the sales behind it needs.
    expect($transactions->count())->toBe(2)
        ->and($transactions->column('bank_reference')?->label)->toBe('Transfer reference');

    foreach ($transactions->rows() as $row) {
        expect($row[$transactions->indexOf('bank_reference')])->toBe('BML-TRX-90863673');
    }

    // The account name travels with it: an account number alone does not
    // reconcile a bounced transfer.
    expect($payouts->column('account_name')?->label)->toBe('Account name')
        ->and($payouts->rows()[0][$payouts->indexOf('account_name')])->toBe('Ami*** Shi***');
});

it('leaves the transfer reference blank on an item the bank never paid', function () {
    $paid = ReportFixture::customer('Aminath Shifa');
    $failed = ReportFixture::customer('Hawwa Latheefa');

    reconcileConfirm($paid, 500_000);
    reconcileConfirm($failed, 600_000);

    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());

    $settler = app(PayoutItemSettler::class);
    $settler->settleOne($batch->items()->where('customer_id', $paid->id)->sole(), 'BML-TRX-1');
    $settler->failOne($batch->items()->where('customer_id', $failed->id)->sole(), 'Account closed');

    $payouts = (new PayoutReport(reconcilePeriod()))->sheet(PayoutReport::PAYOUTS);

    // Blank, not a stale reference from the batch: no transfer happened.
    expect(cellWhere($payouts, 'status', 'failed', 'bank_reference'))->toBe('')
        ->and(cellWhere($payouts, 'status', 'paid', 'bank_reference'))->toBe('BML-TRX-1');
});

it('adds the batch-level instants that say how a batch reached the bank', function () {
    $customer = ReportFixture::customer('Aminath Shifa');
    reconcileConfirm($customer, 500_000);

    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), $this->admin);
    app(ApprovalService::class)->approve($batch, $this->admin);
    app(BankFileExporter::class)->export($batch->refresh());

    app(PayoutItemSettler::class)->settleOne($batch->items()->sole(), 'BML-TRX-1');

    $batches = (new PayoutReport(reconcilePeriod()))->sheet(PayoutReport::BATCHES);

    // payout_batches carries no bank reference of its own — the bank
    // references a transfer, and a batch is many transfers. What it does
    // carry is when the transfer sheet left, which is what a reconciliation
    // chases when a WHOLE batch is unaccounted for.
    expect($batches->column('exported_at')?->label)->toBe('Transfer sheet exported at')
        ->and($batches->column('api_sent_at')?->label)->toBe('Sent to bank at')
        ->and($batches->rows()[0][$batches->indexOf('exported_at')])->not->toBeNull()
        ->and($batches->rows()[0][$batches->indexOf('reference')])->toBe($batch->refresh()->reference);
});

it('carries the withdrawal transfer reference under the same label', function () {
    $customer = ReportFixture::customer('Mariyam Zulfa');

    DB::table('customer_payouts')->insert([
        'customer_id' => $customer->id,
        'amount_laari' => 25_000,
        'currency' => 'MVR',
        'bank' => 'bml',
        'account' => '7730000012345',
        'account_name' => 'Mariyam Zulfa',
        'internal_ref' => 'WD-RECON-1',
        'state' => 'sent',
        'trx_id' => 'BML-W-90863751',
        'requested_at' => CarbonImmutable::now('UTC')->subHour(),
        'processed_at' => CarbonImmutable::now('UTC'),
        'created_at' => CarbonImmutable::now('UTC')->subHour(),
        'updated_at' => CarbonImmutable::now('UTC'),
    ]);

    $withdrawals = (new PayoutReport(reconcilePeriod()))->sheet(PayoutReport::WITHDRAWALS);

    expect($withdrawals->column('trx_id')?->label)->toBe('Transfer reference')
        ->and($withdrawals->rows()[0][$withdrawals->indexOf('trx_id')])->toBe('BML-W-90863751');
});

// ------------------------------ 5: the matched settlement bank references

it('carries both bank references, the payer, the instant and how it was matched', function () {
    $fixture = ReportFixture::payable([100_000]);
    $settlement = $fixture->submit();

    // What the merchant typed on the settle page.
    $payment = app(SettlementAllocator::class)->recordBankPayment(
        $settlement->refresh(),
        Laari::of($fixture->dueTotal()),
        'MERCHANT-SLIP-77',
    );

    // What the bank itself called the money, written by the matcher.
    DB::table('settlement_payments')->where('id', $payment->id)->update([
        'matched_trx_id' => '90863673',
        'matched_trx_refs' => json_encode(['90863673']),
        'matched_payer_name' => 'INTERBRIDGE',
        'auto_matched' => true,
        'matched_by_rule' => 'receipt_reference',
    ]);

    app(SettlementAllocator::class)
        ->matchPayment($payment->refresh(), AdminUser::factory()->create());

    $settlements = (new CashbackReport(reconcilePeriod()))->sheet(CashbackReport::SETTLEMENTS);

    expect($settlements->column('bank_ref_merchant')?->label)->toBe('Bank reference (merchant)')
        ->and($settlements->column('bank_ref_matched')?->label)->toBe('Bank reference (matched)')
        ->and($settlements->column('matched_payer_name')?->label)->toBe('Payer name (bank)');

    $row = $settlements->rows()[0];

    expect($row[$settlements->indexOf('bank_ref_merchant')])->toBe('MERCHANT-SLIP-77')
        // Deduped: matched_trx_refs mirrors matched_trx_id on live rows, and
        // "90863673, 90863673" reads as two transfers.
        ->and($row[$settlements->indexOf('bank_ref_matched')])->toBe('90863673')
        // The payer is a NAME as the bank recorded it — live rows hold plain
        // personal ones — so it masks on screen like every other name.
        ->and($row[$settlements->indexOf('matched_payer_name')])->toBe('INT***')
        ->and($row[$settlements->indexOf('matched_by')])->toBe('Automatic — receipt_reference')
        ->and($row[$settlements->indexOf('matched_at')])->not->toBeNull();

    // And whole in the workbook, which is the render somebody reconciles
    // against a bank statement.
    $exported = (new CashbackReport(reconcilePeriod()))->forExport()->sheet(CashbackReport::SETTLEMENTS);

    expect($exported->rows()[0][$exported->indexOf('matched_payer_name')])->toBe('INTERBRIDGE');
});

it('names the admin behind a hand match rather than saying only "Manual"', function () {
    // "Matched by: Manual" beside a timestamp reads as an identity that was
    // lost — and `settlement_payments.matched_by` is populated on every
    // hand-matched live row.
    $fixture = ReportFixture::payable([100_000]);
    $settlement = $fixture->submit();

    $payment = app(SettlementAllocator::class)->recordBankPayment(
        $settlement->refresh(),
        Laari::of($fixture->dueTotal()),
        null,
    );

    $admin = AdminUser::factory()->create(['name' => 'Ahmed Nazeeh']);

    app(SettlementAllocator::class)->matchPayment($payment->refresh(), $admin);

    $masked = (new CashbackReport(reconcilePeriod()))->sheet(CashbackReport::SETTLEMENTS);
    $full = (new CashbackReport(reconcilePeriod()))->forExport()->sheet(CashbackReport::SETTLEMENTS);

    expect($masked->rows()[0][$masked->indexOf('matched_by')])->toBe('Manual — Ahm*** Naz***')
        ->and($full->rows()[0][$full->indexOf('matched_by')])->toBe('Manual — Ahmed Nazeeh');
});

it('falls back to bare "Manual" when no admin is recorded on the match', function () {
    $fixture = ReportFixture::payable([100_000]);
    $settlement = $fixture->submit();

    $payment = app(SettlementAllocator::class)->recordBankPayment(
        $settlement->refresh(),
        Laari::of($fixture->dueTotal()),
        null,
    );

    // A match with no actor — an automatic run that was not flagged as one.
    app(SettlementAllocator::class)->matchPayment($payment->refresh(), null);

    $settlements = (new CashbackReport(reconcilePeriod()))->sheet(CashbackReport::SETTLEMENTS);

    expect($settlements->rows()[0][$settlements->indexOf('matched_by')])->toBe('Manual');
});

it('aggregates every matched payment on one settlement, not just the first', function () {
    // A merchant who paid in two transfers — the case a ->value() lookup
    // would silently report as one, hiding half the money's provenance.
    $fixture = ReportFixture::payable([100_000, 50_000]);
    $settlement = $fixture->submit();

    $allocator = app(SettlementAllocator::class);
    $admin = AdminUser::factory()->create(['name' => 'Ahmed Nazeeh']);

    $first = $fixture->due(0);
    $second = $fixture->dueTotal() - $first;

    foreach ([
        [$first, 'SLIP-A', '90863673', 'INTERBRIDGE', true, 'receipt_reference'],
        [$second, 'SLIP-B', '90863751', 'SEA HOUSE PVT LTD', false, null],
    ] as [$amount, $slip, $trxId, $payer, $auto, $rule]) {
        $payment = $allocator->recordBankPayment($settlement->refresh(), Laari::of($amount), $slip);

        DB::table('settlement_payments')->where('id', $payment->id)->update([
            'matched_trx_id' => $trxId,
            'matched_trx_refs' => json_encode([$trxId]),
            'matched_payer_name' => $payer,
            'auto_matched' => $auto,
            'matched_by_rule' => $rule,
        ]);

        $allocator->matchPayment($payment->refresh(), $admin);
    }

    $settlements = (new CashbackReport(reconcilePeriod()))->sheet(CashbackReport::SETTLEMENTS);
    $row = $settlements->rows()[0];

    expect($settlements->count())->toBe(1)
        ->and($row[$settlements->indexOf('bank_ref_merchant')])->toBe('SLIP-A, SLIP-B')
        ->and($row[$settlements->indexOf('bank_ref_matched')])->toBe('90863673, 90863751')
        // Masked PER NAME, before the join — a two-payment settlement masks
        // both payers rather than starring the pair as one string.
        ->and($row[$settlements->indexOf('matched_payer_name')])
        ->toBe('INT***, SEA*** HOU*** PVT*** LTD***')
        // Mixed provenance stated as mixed, rather than as whichever payment
        // happened to be read last — and the hand match names its admin.
        ->and($row[$settlements->indexOf('matched_by')])
        ->toBe('Automatic — receipt_reference, Manual — Ahm*** Naz***');
});

it('merges extra identifiers from matched_trx_refs without repeating the trx id', function () {
    $fixture = ReportFixture::payable([100_000]);
    $settlement = $fixture->submit();

    $payment = app(SettlementAllocator::class)->recordBankPayment(
        $settlement->refresh(),
        Laari::of($fixture->dueTotal()),
        null,
    );

    DB::table('settlement_payments')->where('id', $payment->id)->update([
        'matched_trx_id' => '90863673',
        // The matcher captured a second identifier off the receipt as well.
        'matched_trx_refs' => json_encode(['90863673', 'FT26081200099']),
        'auto_matched' => true,
        'matched_by_rule' => 'amount_and_window',
    ]);

    app(SettlementAllocator::class)
        ->matchPayment($payment->refresh(), AdminUser::factory()->create());

    $settlements = (new CashbackReport(reconcilePeriod()))->sheet(CashbackReport::SETTLEMENTS);
    $row = $settlements->rows()[0];

    expect($row[$settlements->indexOf('bank_ref_matched')])->toBe('90863673, FT26081200099')
        // None of the eight live settlements carry a merchant-typed
        // reference, so blank is the ordinary case and must read as blank.
        ->and($row[$settlements->indexOf('bank_ref_merchant')])->toBe('')
        ->and($row[$settlements->indexOf('matched_by')])->toBe('Automatic — amount_and_window');
});

it('shows nothing for a settlement no payment has been matched against', function () {
    $fixture = ReportFixture::payable([100_000]);
    $fixture->submit();

    $settlements = (new CashbackReport(reconcilePeriod()))->sheet(CashbackReport::SETTLEMENTS);
    $row = $settlements->rows()[0];

    // Submitted, awaiting money. Every reconciliation cell is blank rather
    // than zero or a placeholder — nothing has arrived to reconcile.
    expect($settlements->count())->toBe(1)
        ->and($row[$settlements->indexOf('bank_ref_merchant')])->toBe('')
        ->and($row[$settlements->indexOf('bank_ref_matched')])->toBe('')
        ->and($row[$settlements->indexOf('matched_payer_name')])->toBe('')
        ->and($row[$settlements->indexOf('matched_by')])->toBe('')
        ->and($row[$settlements->indexOf('matched_at')])->toBeNull();
});

it('ignores a pending or rejected payment, which is not money that arrived', function () {
    $fixture = ReportFixture::payable([100_000]);
    $settlement = $fixture->submit();

    // Recorded but never matched: a claim nobody has checked.
    app(SettlementAllocator::class)->recordBankPayment(
        $settlement->refresh(),
        Laari::of($fixture->dueTotal()),
        'UNCHECKED-SLIP',
    );

    $settlements = (new CashbackReport(reconcilePeriod()))->sheet(CashbackReport::SETTLEMENTS);
    $row = $settlements->rows()[0];

    // Printing it beside a settled amount would invite somebody to
    // reconcile against money that never arrived.
    expect($row[$settlements->indexOf('bank_ref_merchant')])->toBe('')
        ->and($row[$settlements->indexOf('bank_ref_matched')])->toBe('');
});
