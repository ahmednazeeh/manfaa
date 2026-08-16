<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Payout\ApprovalService;
use App\Domain\Payout\BankFileExporter;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Payout\PayoutItemState;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\Support\TransferSheet;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    runOn('2026-08-26');

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
    $this->creditor = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->admin = AdminUser::factory()->create();
    $this->actingAs($this->admin, 'admin');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The seven columns, in the order the owner asked for them. Stated in full
 * rather than read off the exporter's own constant: the order is the contract
 * with the bank and with the importer, and a test that borrows the definition
 * it is checking cannot notice it change.
 *
 * @return list<string>
 */
function sheetHeadings(): array
{
    return [
        'Idempotency Key',
        'Customer Name',
        'Customer Phone',
        'Customer Account Name',
        'Customer Account Number',
        'Amount Owed',
        'Transfer Reference Number',
    ];
}

/**
 * Stands the clock at noon of a business day — the day an admin sits down to
 * run a batch. A build ahead of its own cutoff is refused, so every cutoff
 * below names a date the clock has already reached.
 */
function runOn(string $date): void
{
    Carbon::setTestNow(CarbonImmutable::parse($date.'T12:00:00+05:00'));
}

/**
 * The instant recorded for a cutoff date the clock has already passed: the
 * last second of that day in business time. A cutoff of TODAY is recorded as
 * the clock instead, so today's run is not refused as ahead of itself.
 */
function endOfBusinessDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date.'T23:59:59+05:00');
}

/**
 * A customer with complete §13 bank details — the default fixture customer
 * for payout tests, since a detail-less customer is excluded from batches.
 */
function payoutCustomer(array $attributes = []): Customer
{
    return Customer::factory()->create($attributes + [
        'payout_bank' => 'bml',
        'payout_account' => (string) fake()->unique()->numberBetween(7730000000000, 7739999999999),
        'payout_account_name' => fake()->name(),
    ]);
}

/**
 * Creates a manual credit and drives it to confirmed through the real
 * services (creation event, accrual journal, clock start, confirmation
 * event), then pins its confirmation instant for cutoff arithmetic.
 *
 * By default the instant lives only in the event log — the fallback path.
 * With $viaConfirmedAtColumn the transactions.confirmed_at column is stamped
 * (settlement's path) and the event log is deliberately set far in the
 * future, proving the column takes precedence when both exist.
 */
function confirmTransactionAt(
    Merchant $merchant,
    MerchantUser $creditor,
    Customer $customer,
    int $eligibleLaari,
    CarbonImmutable $confirmedAt,
    bool $viaConfirmedAtColumn = false,
): Transaction {
    $transitions = app(TransitionService::class);

    $transaction = app(ManualCreditService::class)->credit(
        $merchant,
        $creditor,
        $customer->customer_code,
        'INV-'.Str::upper(Str::random(12)),
        Laari::of($eligibleLaari),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    );

    $transitions->makePayable($transaction, Actor::system());
    $transitions->confirm($transaction, Actor::system());

    $eventTimestamp = $viaConfirmedAtColumn ? $confirmedAt->utc()->addYears(5) : $confirmedAt->utc();

    DB::table('transaction_events')
        ->where('transaction_id', $transaction->id)
        ->where('to_state', TransactionState::Confirmed->value)
        ->update(['created_at' => $eventTimestamp]);

    if ($viaConfirmedAtColumn) {
        $transaction->forceFill(['confirmed_at' => $confirmedAt->utc()])->save();
    }

    return $transaction->refresh();
}

/**
 * Approves through the real service, for the tests whose subject is what
 * comes after approval rather than approval itself.
 */
function approveBatch(PayoutBatch $batch): PayoutBatch
{
    app(ApprovalService::class)->approve($batch, AdminUser::factory()->create());

    return $batch->refresh();
}

/**
 * Approves and exports, returning the rendered sheet — the state every
 * settlement test has to start from, since an outcome cannot be recorded
 * before the file is with the bank.
 */
function exportedSheet(PayoutBatch $batch): string
{
    approveBatch($batch);

    return app(BankFileExporter::class)->export($batch);
}

/**
 * What the ledger says one item's transfer cost: the debits posted against
 * the customer liability by that item's payoutSent journal. Zero when no
 * journal exists, which is the answer an unpaid item should give.
 */
function payoutDebitsFor(PayoutItem $item): int
{
    return (int) DB::table('ledger_entries')
        ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_journals.reference_type', 'payout_item')
        ->where('ledger_journals.reference_id', $item->id)
        ->where('ledger_accounts.code', AccountCode::CustomerCashbackLiability->value)
        ->sum('ledger_entries.debit_laari');
}

it('includes confirmations up to the cutoff instant and rolls later ones into the next batch', function () {
    $inside = payoutCustomer();
    $outside = payoutCustomer();
    $viaColumn = payoutCustomer();

    // The last second of the chosen day is in; the first minute of the next
    // day is out.
    $inTx = confirmTransactionAt($this->merchant, $this->creditor, $inside, 500000, CarbonImmutable::parse('2026-08-24T23:59:00+05:00'));
    $outTx = confirmTransactionAt($this->merchant, $this->creditor, $outside, 500000, CarbonImmutable::parse('2026-08-25T00:01:00+05:00'));

    // Settlement's confirmed_at column wins over a (deliberately absurd)
    // event timestamp — both recording styles are supported.
    $columnTx = confirmTransactionAt($this->merchant, $this->creditor, $viaColumn, 500000, CarbonImmutable::parse('2026-08-24T12:00:00+05:00'), viaConfirmedAtColumn: true);

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-20260824')
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.customer_count', 2)
        ->assertJsonPath('data.total_laari', 20000);

    $batch = PayoutBatch::query()->sole();

    expect($batch->cutoff_at->equalTo(endOfBusinessDay('2026-08-24')))->toBeTrue()
        ->and($batch->period_end->toDateString())->toBe('2026-08-24')
        ->and($batch->items()->pluck('customer_id')->all())
        ->toEqualCanonicalizing([$inside->id, $viaColumn->id]);

    expect($inTx->refresh()->payout_item_id)->not->toBeNull()
        ->and($columnTx->refresh()->payout_item_id)->not->toBeNull()
        ->and($outTx->refresh()->payout_item_id)->toBeNull();
});

it('excludes customers below MVR 100 and includes them in the next batch once topped up', function () {
    $below = payoutCustomer();
    $at = payoutCustomer();

    // 499,950 @ 200bp ceils to 9,999 laari — one short of the minimum.
    confirmTransactionAt($this->merchant, $this->creditor, $below, 499950, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));
    // 500,000 @ 200bp is exactly 10,000 laari — included.
    confirmTransactionAt($this->merchant, $this->creditor, $at, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();

    $first = PayoutBatch::query()->where('reference', 'PB-20260824')->sole();

    expect($first->items()->count())->toBe(1)
        ->and($first->items()->sole()->customer_id)->toBe($at->id)
        ->and($first->items()->sole()->amount_laari)->toBe(10000);

    // The 9,999 carried forward automatically: a 100-laari top-up clears the
    // bar, and both transactions pay out together in the following week's run.
    runOn('2026-08-29');
    confirmTransactionAt($this->merchant, $this->creditor, $below, 5000, CarbonImmutable::parse('2026-08-27T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-28'])->assertCreated();

    $second = PayoutBatch::query()->where('reference', 'PB-20260828')->sole();
    $item = $second->items()->sole();

    expect($item->customer_id)->toBe($below->id)
        ->and($item->amount_laari)->toBe(9999 + 100)
        ->and(Transaction::query()->where('customer_id', $below->id)->whereNull('payout_item_id')->count())->toBe(0);
});

it('sweeps a reward confirmed after one batch into the next, losing nothing', function () {
    // The owner's scenario: a batch is built late in August, and a reward
    // confirmed AFTER its cutoff has to turn up somewhere. It does — the
    // eligibility query has no lower bound, only a cutoff, so the next batch
    // reaches back and collects everything still unlinked. Nothing is lost;
    // it is only late, which is the whole argument for a chosen cutoff date
    // rather than a fixed monthly one.
    $customer = payoutCustomer();

    confirmTransactionAt($this->merchant, $this->creditor, $customer, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));
    $stranded = confirmTransactionAt($this->merchant, $this->creditor, $customer, 700000, CarbonImmutable::parse('2026-08-28T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();

    $august = PayoutBatch::query()->where('reference', 'PB-20260824')->sole();

    expect($august->items()->sole()->amount_laari)->toBe(10000)
        ->and($stranded->refresh()->payout_item_id)->toBeNull();

    runOn('2026-09-26');
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-09-24'])->assertCreated();

    $september = PayoutBatch::query()->where('reference', 'PB-20260924')->sole();

    // 700,000 @ 200bp = 14,000 laari, collected a month after it was earned.
    expect($september->items()->sole()->amount_laari)->toBe(14000)
        ->and($stranded->refresh()->payout_item_id)->toBe($september->items()->sole()->id)
        ->and(Transaction::query()->whereNull('payout_item_id')->count())->toBe(0);
});

it('runs weekly: three batches seven days apart, nothing counted twice and nothing left behind', function () {
    // The point of the whole round. Each Saturday the admin builds to the
    // previous day's cutoff; each run must collect exactly what confirmed
    // since the last one collected, no more and no less.
    $customer = payoutCustomer();

    // Week one. The 8 August reward confirms on the run day itself — after
    // the 7 August cutoff — and is the row that used to be stranded for a
    // month under a fixed monthly cutoff.
    runOn('2026-08-08');
    $week1 = [
        confirmTransactionAt($this->merchant, $this->creditor, $customer, 300000, CarbonImmutable::parse('2026-08-03T10:00:00+05:00')), // 6,000
        confirmTransactionAt($this->merchant, $this->creditor, $customer, 250000, CarbonImmutable::parse('2026-08-06T10:00:00+05:00')), // 5,000
    ];
    $afterCutoff = confirmTransactionAt($this->merchant, $this->creditor, $customer, 400000, CarbonImmutable::parse('2026-08-08T10:00:00+05:00')); // 8,000

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-07'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-20260807')
        ->assertJsonPath('data.customer_count', 1)
        ->assertJsonPath('data.total_laari', 11000);

    // Week two sweeps the 8 August reward in with its own.
    runOn('2026-08-15');
    $week2 = [confirmTransactionAt($this->merchant, $this->creditor, $customer, 200000, CarbonImmutable::parse('2026-08-12T10:00:00+05:00'))]; // 4,000

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-14'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-20260814')
        ->assertJsonPath('data.total_laari', 12000);

    // Week three.
    runOn('2026-08-22');
    $week3 = [
        confirmTransactionAt($this->merchant, $this->creditor, $customer, 350000, CarbonImmutable::parse('2026-08-16T10:00:00+05:00')), // 7,000
        confirmTransactionAt($this->merchant, $this->creditor, $customer, 300000, CarbonImmutable::parse('2026-08-19T10:00:00+05:00')), // 6,000
    ];

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-21'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-20260821')
        ->assertJsonPath('data.total_laari', 13000);

    $batches = PayoutBatch::query()->orderBy('id')->get();
    $items = PayoutItem::query()->orderBy('id')->get();

    // Three runs, one item each, and every laari of the 36,000 confirmed sits
    // in exactly one of them.
    expect($items->pluck('amount_laari')->all())->toBe([11000, 12000, 13000])
        ->and($items->sum('amount_laari'))->toBe((int) Transaction::query()->sum('cashback_laari'))
        ->and(Transaction::query()->whereNull('payout_item_id')->count())->toBe(0);

    // Each reward is in the run that follows its confirmation, and no other.
    foreach ([[$week1, 0], [[$afterCutoff, ...$week2], 1], [$week3, 2]] as [$transactions, $index]) {
        foreach ($transactions as $transaction) {
            expect($transaction->refresh()->payout_item_id)->toBe($items[$index]->id);
        }
    }

    // The displayed period chains run to run: each opens where the last one
    // closed, and the first opens at the oldest confirmation on record.
    expect($batches->map(fn (PayoutBatch $batch) => [
        $batch->period_start->toDateString(),
        $batch->period_end->toDateString(),
    ])->all())->toBe([
        ['2026-08-03', '2026-08-07'],
        ['2026-08-07', '2026-08-14'],
        ['2026-08-14', '2026-08-21'],
    ]);
});

it('approves once, and refuses approval of a batch that is no longer a draft', function () {
    confirmTransactionAt($this->merchant, $this->creditor, payoutCustomer(), 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    // One admin is enough: the approval both records the approver and moves
    // the batch, in one act.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.approved_by', $this->admin->id);

    expect($batch->refresh()->state)->toBe(PayoutBatchState::Approved)
        ->and($batch->approved_at)->not->toBeNull();

    // Approving again is refused whoever asks — the batch is not a draft any
    // more, and a second approval would restamp who signed it off.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/approve")->assertConflict();

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertConflict();

    expect($batch->refresh()->approved_by)->toBe($this->admin->id);
});

it('exports the seven-column transfer sheet only from approved, with a numeric amount and an empty reference', function () {
    $customer = payoutCustomer([
        'name' => 'Aishath Nazeeh',
        'phone' => '+9607712345',
        'payout_account' => '7730000000001',
        // A cell opening with = is a formula to a spreadsheet unless it is
        // written as text, which is how every text cell on this sheet is
        // written.
        'payout_account_name' => '=cmd',
    ]);

    // 591,250 @ 200bp ceils to exactly 11,825 laari = MVR 118.25.
    confirmTransactionAt($this->merchant, $this->creditor, $customer, 591250, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    // A draft batch has no transfer sheet — approval first.
    $this->post("/api/admin/payout-batches/{$batch->id}/export", [], ['Accept' => 'application/json'])
        ->assertConflict();

    approveBatch($batch);

    $response = $this->post("/api/admin/payout-batches/{$batch->id}/export");
    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('PB-20260824.xlsx');

    $item = $batch->items()->sole();
    $sheet = TransferSheet::open($response->getContent());
    $amount = $sheet->getCell('F2');

    expect($sheet->rangeToArray('A1:G1', null, false, false, false)[0])->toBe(sheetHeadings())
        ->and($sheet->getCell('A2')->getValue())->toBe($item->idempotency_key)
        ->and($sheet->getCell('B2')->getValue())->toBe('Aishath Nazeeh')
        ->and($sheet->getCell('C2')->getValue())->toBe('+9607712345')
        ->and($sheet->getCell('D2')->getValue())->toBe('=cmd')
        ->and($sheet->getCell('D2')->getDataType())->toBe(DataType::TYPE_STRING)
        // A long account number written as text stays digit for digit rather
        // than turning into 7.73E+12.
        ->and($sheet->getCell('E2')->getValue())->toBe('7730000000001')
        ->and($sheet->getCell('E2')->getDataType())->toBe(DataType::TYPE_STRING)
        // Amount Owed is a number, not a formatted string: finance sums this
        // column, and a string column sums to nothing.
        ->and($amount->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($amount->getValue())->toBe(118.25)
        ->and($amount->getValue())->toBe($item->amount_laari / 100)
        ->and($sheet->getStyle('F2')->getNumberFormat()->getFormatCode())->toBe('#,##0.00')
        // The box the bank's reference goes in, left empty on purpose.
        ->and($sheet->getCell('G2')->getValue())->toBeNull();

    $batch->refresh();

    expect($batch->state)->toBe(PayoutBatchState::Processing)
        ->and($batch->exported_at)->not->toBeNull()
        ->and($item->refresh()->state)->toBe(PayoutItemState::Sent);

    // A lost download is recoverable: while the batch is processing and no
    // result has arrived, re-export renders the same sheet without touching
    // state. The same CONTENT, not the same bytes — an xlsx is a zip, and a
    // zip stamps itself with the moment it was written.
    $again = $this->post("/api/admin/payout-batches/{$batch->id}/export");
    $again->assertOk();

    expect(TransferSheet::cells($again->getContent()))->toBe(TransferSheet::cells($response->getContent()))
        ->and($batch->refresh()->state)->toBe(PayoutBatchState::Processing)
        ->and($batch->items()->where('state', PayoutItemState::Sent)->count())->toBe(1);

    // Once the bank's results start landing, a fresh file would diverge from
    // the one the bank acted on — export closes.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => TransferSheet::filled($response->getContent(), [$item->idempotency_key => 'BML-R1']),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->post("/api/admin/payout-batches/{$batch->id}/export", [], ['Accept' => 'application/json'])
        ->assertConflict();
});

it('exports what was true when the batch was built, not what the customer changed afterwards', function () {
    $customer = payoutCustomer([
        'name' => 'Aishath Nazeeh',
        'phone' => '+9607712345',
        'payout_bank' => 'bml',
        'payout_account' => '7730000000001',
        'payout_account_name' => 'AISHATH NAZEEH',
    ]);

    confirmTransactionAt($this->merchant, $this->creditor, $customer, 591250, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();
    $item = $batch->items()->sole();

    // Everything about the payee changes after the batch exists: they marry,
    // they move bank. A transfer instruction already agreed must not follow
    // them, or finance and the bank hold two different sheets.
    $customer->forceFill([
        'name' => 'Aishath Nazeeh Ibrahim',
        'phone' => '+9607799999',
        'payout_bank' => 'mib',
        'payout_account' => '9990000000009',
        'payout_account_name' => 'A N IBRAHIM',
    ])->save();

    $sheet = TransferSheet::open(exportedSheet($batch));

    expect($sheet->getCell('B2')->getValue())->toBe('Aishath Nazeeh')
        ->and($sheet->getCell('C2')->getValue())->toBe('+9607712345')
        ->and($sheet->getCell('D2')->getValue())->toBe('AISHATH NAZEEH')
        ->and($sheet->getCell('E2')->getValue())->toBe('7730000000001');

    expect($item->refresh()->customer_name)->toBe('Aishath Nazeeh')
        ->and($item->customer_phone)->toBe('+9607712345')
        ->and($item->bank)->toBe('bml')
        ->and($item->account)->toBe('7730000000001')
        ->and($item->account_name)->toBe('AISHATH NAZEEH');
});

it('mints an idempotency key that is unique across batches, stable across exports, and never reused after a failure', function () {
    $failing = payoutCustomer();
    $paying = payoutCustomer();

    confirmTransactionAt($this->merchant, $this->creditor, $failing, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));  // 10,000
    confirmTransactionAt($this->merchant, $this->creditor, $paying, 600000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));   // 12,000

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    $keys = $batch->items()->orderBy('id')->pluck('idempotency_key')->all();

    expect($keys)->toHaveCount(2)
        ->and(array_unique($keys))->toHaveCount(2);

    foreach ($keys as $key) {
        expect($key)->toMatch('/^MNF\d{6}$/');
    }

    // The key is persisted at build time, so two exports of the same batch
    // say the same thing. A key that changed between them would not be one.
    approveBatch($batch);
    $first = $this->post("/api/admin/payout-batches/{$batch->id}/export")->getContent();
    $second = $this->post("/api/admin/payout-batches/{$batch->id}/export")->getContent();

    $keyColumn = fn (string $xlsx): array => array_column(array_slice(TransferSheet::cells($xlsx), 1), 0);

    expect($keyColumn($first))->toBe($keys)
        ->and($keyColumn($second))->toBe($keys);

    // A rejected transfer re-queues the money, and the item that carries it
    // next is a NEW attempt at the bank — so it gets a new key, never the one
    // the bank already saw.
    $failed = $batch->items()->where('customer_id', $failing->id)->sole();

    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$failed->id}/mark-failed", [
        'failure_reason' => 'Account closed',
    ])->assertOk();

    runOn('2026-08-29');
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-28'])->assertCreated();

    $requeued = PayoutBatch::query()->where('reference', 'PB-20260828')->sole()->items()->sole();

    expect($requeued->customer_id)->toBe($failing->id)
        ->and($requeued->amount_laari)->toBe(10000)
        ->and($requeued->idempotency_key)->toMatch('/^MNF\d{6}$/')
        ->and($keys)->not->toContain($requeued->idempotency_key);
});

it('applies a filled transfer sheet: pays, posts one journal per item, and re-imports as a no-op', function () {
    $customer = payoutCustomer();

    $confirmedAt = CarbonImmutable::parse('2026-08-20T10:00:00+05:00');
    $paidTxA = confirmTransactionAt($this->merchant, $this->creditor, $customer, 500000, $confirmedAt);  // 10,000
    $paidTxB = confirmTransactionAt($this->merchant, $this->creditor, $customer, 100000, $confirmedAt);  // 2,000

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    // A filled sheet cannot land before the transfer sheet went out.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('transfers.csv', "Idempotency Key,Transfer Reference Number\nMNF000001,BML-1\n"),
    ], ['Accept' => 'application/json'])->assertConflict();

    $exported = exportedSheet($batch);
    $item = $batch->items()->sole();

    expect($item->amount_laari)->toBe(12000);

    $balances = new Balances;
    $liabilityBefore = $balances->naturalBalance(AccountCode::CustomerCashbackLiability);
    expect($liabilityBefore)->toBe(12000);

    $filled = fn (): UploadedFile => TransferSheet::filled($exported, [$item->idempotency_key => 'BML-REF-1']);

    $this->post("/api/admin/payout-batches/{$batch->id}/import", ['file' => $filled()], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed');

    // Both transactions moved confirmed → paid through the state machine,
    // with the event evidencing actor and reason.
    foreach ([$paidTxA, $paidTxB] as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Paid);

        $event = $transaction->events()->where('to_state', 'paid')->get();
        expect($event)->toHaveCount(1)
            ->and($event->first()->actor_type)->toBe('system')
            ->and($event->first()->reason_code)->toBe('payout_completed');
    }

    // Exactly ONE payoutSent journal, for the item's stored sum — and the
    // liability shrank by exactly that integer.
    expect(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1)
        ->and(payoutDebitsFor($item))->toBe(12000)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($liabilityBefore - 12000)
        ->and($item->refresh()->state)->toBe(PayoutItemState::Paid)
        ->and($item->bank_reference)->toBe('BML-REF-1');

    // Uploading the same sheet again changes nothing: the bank is working
    // down one file and sends it back as often as it likes.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", ['file' => $filled()], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed');

    expect(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1)
        ->and($paidTxA->refresh()->events()->where('to_state', 'paid')->count())->toBe(1)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($liabilityBefore - 12000);

    // A second, DIFFERENT reference on a row already paid contradicts a
    // transfer the ledger has recorded, and is refused.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => TransferSheet::filled($exported, [$item->idempotency_key => 'BML-REF-2']),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($item->refresh()->bank_reference)->toBe('BML-REF-1');
});

it('refuses a sheet belonging to another batch, and pays a half-filled one a row at a time', function () {
    $one = payoutCustomer();
    $two = payoutCustomer();
    $elsewhere = payoutCustomer();

    confirmTransactionAt($this->merchant, $this->creditor, $one, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));  // 10,000
    confirmTransactionAt($this->merchant, $this->creditor, $two, 600000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));  // 12,000

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $mine = PayoutBatch::query()->sole();
    $mineSheet = exportedSheet($mine);

    // A second run, with keys of its own.
    confirmTransactionAt($this->merchant, $this->creditor, $elsewhere, 700000, CarbonImmutable::parse('2026-08-26T10:00:00+05:00')); // 14,000

    runOn('2026-08-29');
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-28'])->assertCreated();
    $theirs = PayoutBatch::query()->where('reference', 'PB-20260828')->sole();
    $theirsSheet = exportedSheet($theirs);
    $theirsItem = $theirs->items()->sole();

    // The other run's filled sheet, uploaded against this one. Every key is
    // foreign, so the upload is refused whole and neither batch moves.
    $this->post("/api/admin/payout-batches/{$mine->id}/import", [
        'file' => TransferSheet::filled($theirsSheet, [$theirsItem->idempotency_key => 'BML-WRONG-BATCH']),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($mine->refresh()->state)->toBe(PayoutBatchState::Processing)
        ->and($mine->items()->where('state', PayoutItemState::Sent)->count())->toBe(2)
        ->and($theirsItem->refresh()->state)->toBe(PayoutItemState::Sent)
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(0);

    $itemOne = $mine->items()->where('customer_id', $one->id)->sole();
    $itemTwo = $mine->items()->where('customer_id', $two->id)->sole();

    // Half filled: the bank has paid one payee so far and sends the sheet
    // back as it stands. The blank row is not a claim about anything.
    $this->post("/api/admin/payout-batches/{$mine->id}/import", [
        'file' => TransferSheet::filled($mineSheet, [$itemOne->idempotency_key => 'BML-HALF-1']),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'sent');

    expect($itemOne->refresh()->state)->toBe(PayoutItemState::Paid)
        ->and($itemOne->bank_reference)->toBe('BML-HALF-1')
        ->and($itemTwo->refresh()->state)->toBe(PayoutItemState::Sent)
        ->and($itemTwo->bank_reference)->toBeNull()
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1);

    // The rest arrives on the next upload of the same sheet, now with both
    // rows filled. The row already applied is passed over in silence.
    $this->post("/api/admin/payout-batches/{$mine->id}/import", [
        'file' => TransferSheet::filled($mineSheet, [
            $itemOne->idempotency_key => 'BML-HALF-1',
            $itemTwo->idempotency_key => 'BML-HALF-2',
        ]),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed');

    expect($itemTwo->refresh()->state)->toBe(PayoutItemState::Paid)
        ->and($itemTwo->bank_reference)->toBe('BML-HALF-2')
        ->and(payoutDebitsFor($itemOne))->toBe(10000)
        ->and(payoutDebitsFor($itemTwo))->toBe(12000)
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(2);
});

it('reads the transfer sheet back as CSV, past a byte-order mark and a title line', function () {
    confirmTransactionAt($this->merchant, $this->creditor, payoutCustomer(), 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    exportedSheet($batch);
    $item = $batch->items()->sole();

    // What comes back when finance saves the sheet as "CSV UTF-8" and leaves
    // a title above the table: a byte-order mark, a stray line, a blank line,
    // then the headings. None of it is a claim about a transfer.
    $csv = implode("\r\n", [
        "\u{FEFF}Manfaa transfer sheet",
        '',
        implode(',', sheetHeadings()),
        "{$item->idempotency_key},Aishath,7712345,Aishath,7730000000001,100.00,BML-BOM-1",
        '',
    ]);

    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('transfers.csv', $csv),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed');

    expect($item->refresh()->state)->toBe(PayoutItemState::Paid)
        ->and($item->bank_reference)->toBe('BML-BOM-1')
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1);
});

it('refuses a file that is not a transfer sheet at all', function () {
    confirmTransactionAt($this->merchant, $this->creditor, payoutCustomer(), 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    exportedSheet($batch);

    // A sheet with neither of the two columns the importer reads: refused
    // before a single row is considered.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('transfers.csv', "Payee,Amount\nAishath,100.00\n"),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($batch->refresh()->state)->toBe(PayoutBatchState::Processing)
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(0);
});

it('records one transfer paid and another failed by hand, with the ledger the sheet would have written', function () {
    $paidCustomer = payoutCustomer();
    $failedCustomer = payoutCustomer();

    $confirmedAt = CarbonImmutable::parse('2026-08-20T10:00:00+05:00');
    $paidTxA = confirmTransactionAt($this->merchant, $this->creditor, $paidCustomer, 500000, $confirmedAt);   // 10,000
    $paidTxB = confirmTransactionAt($this->merchant, $this->creditor, $paidCustomer, 100000, $confirmedAt);   // 2,000
    $failedTx = confirmTransactionAt($this->merchant, $this->creditor, $failedCustomer, 500000, $confirmedAt); // 10,000

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    // Nothing to have an outcome yet: the file is not with the bank.
    $firstItem = $batch->items()->orderBy('id')->first();
    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$firstItem->id}/mark-paid", [
        'bank_reference' => 'BML-TOO-EARLY',
    ])->assertConflict();

    exportedSheet($batch);

    $paidItem = $batch->items()->where('customer_id', $paidCustomer->id)->sole();
    $failedItem = $batch->items()->where('customer_id', $failedCustomer->id)->sole();

    $balances = new Balances;
    $liabilityBefore = $balances->naturalBalance(AccountCode::CustomerCashbackLiability);
    expect($liabilityBefore)->toBe(22000);

    // Mark paid: the same ledger path an uploaded sheet takes — one journal
    // for the item's stored integer, both transactions through the state
    // machine. D7: the items come back with the batch in one round-trip.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$paidItem->id}/mark-paid", [
        'bank_reference' => 'BML-HAND-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'sent')
        ->assertJsonCount(2, 'data.items');

    foreach ([$paidTxA, $paidTxB] as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Paid)
            ->and($transaction->events()->where('to_state', 'paid')->sole()->reason_code)->toBe('payout_completed');
    }

    expect(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1)
        ->and(payoutDebitsFor($paidItem))->toBe(12000)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($liabilityBefore - 12000)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // Mark failed: the item carries the reason and no reference, its
    // transaction is unlinked and STAYS confirmed, and nothing is posted —
    // money that never left cannot be released.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$failedItem->id}/mark-failed", [
        'failure_reason' => 'Account closed',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'partially_failed');

    expect($failedItem->refresh()->state)->toBe(PayoutItemState::Failed)
        ->and($failedItem->failure_reason)->toBe('Account closed')
        ->and($failedItem->bank_reference)->toBeNull()
        ->and($failedTx->refresh()->payout_item_id)->toBeNull()
        ->and($failedTx->state)->toBe(TransactionState::Confirmed)
        ->and($failedTx->events()->where('to_state', 'paid')->count())->toBe(0)
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($liabilityBefore - 12000);

    // Paid and failed are terminal (D5): a second outcome would post a second
    // journal, or re-queue money that has already left the bank.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$paidItem->id}/mark-paid", [
        'bank_reference' => 'BML-HAND-2',
    ])->assertUnprocessable();

    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$failedItem->id}/mark-paid", [
        'bank_reference' => 'BML-HAND-3',
    ])->assertUnprocessable();

    expect($paidItem->refresh()->bank_reference)->toBe('BML-HAND-1');

    // The failed money re-enters the next build, and its new item cannot be
    // settled through the old batch's route — the binding is scoped, so an
    // item this batch does not own is simply not found.
    runOn('2026-08-29');
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-28'])->assertCreated();

    $requeued = PayoutBatch::query()->where('reference', 'PB-20260828')->sole()->items()->sole();

    expect($requeued->customer_id)->toBe($failedCustomer->id)
        ->and($requeued->amount_laari)->toBe(10000)
        ->and($failedTx->refresh()->payout_item_id)->toBe($requeued->id);

    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$requeued->id}/mark-paid", [
        'bank_reference' => 'BML-WRONG-BATCH',
    ])->assertNotFound();

    expect($requeued->refresh()->state)->toBe(PayoutItemState::Pending);
});

it('settles every outstanding item under one shared reference, and refuses to settle without one', function () {
    $customers = collect([payoutCustomer(), payoutCustomer(), payoutCustomer()]);
    $confirmedAt = CarbonImmutable::parse('2026-08-20T10:00:00+05:00');

    foreach ([500000, 600000, 700000] as $index => $eligible) {
        confirmTransactionAt($this->merchant, $this->creditor, $customers[$index], $eligible, $confirmedAt);
    }

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $batch = PayoutBatch::query()->sole();
    exportedSheet($batch);

    // D4: a bulk transfer settles as ONE bank transaction, so the reference
    // is the record of it. Without one, settle-all would paint every row paid
    // with nothing to point at.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/settle-all", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bank_reference');

    expect($batch->refresh()->items()->where('state', PayoutItemState::Sent)->count())->toBe(3)
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(0);

    // One payee was already settled on its own. Settle-all sweeps what is
    // left and passes over that row rather than paying it twice.
    $settled = $batch->items()->where('customer_id', $customers[0]->id)->sole();

    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$settled->id}/mark-paid", [
        'bank_reference' => 'BML-ONE-OFF',
    ])->assertOk();

    $balances = new Balances;
    $liabilityBefore = $balances->naturalBalance(AccountCode::CustomerCashbackLiability);

    $this->postJson("/api/admin/payout-batches/{$batch->id}/settle-all", ['bank_reference' => 'BML-BULK-1'])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonCount(3, 'data.items');

    $items = $batch->items()->orderBy('id')->get();

    expect($items->pluck('state')->all())->toBe([PayoutItemState::Paid, PayoutItemState::Paid, PayoutItemState::Paid])
        ->and($settled->refresh()->bank_reference)->toBe('BML-ONE-OFF')
        ->and($items->where('id', '!=', $settled->id)->pluck('bank_reference')->unique()->values()->all())->toBe(['BML-BULK-1']);

    // One journal per item, each for that item's stored integer — the same
    // shape the importer writes, never one journal for the batch total.
    expect(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(3);

    foreach ($items as $item) {
        expect(payoutDebitsFor($item))->toBe($item->amount_laari);
    }

    expect($balances->naturalBalance(AccountCode::CustomerCashbackLiability))
        ->toBe($liabilityBefore - (int) $items->where('id', '!=', $settled->id)->sum('amount_laari'))
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and(Transaction::query()->where('state', '!=', TransactionState::Paid->value)->count())->toBe(0);
});

it('never includes a linked transaction twice, and cancel unlinks so the same cutoff can be rebuilt', function () {
    $customer = payoutCustomer();
    $transaction = confirmTransactionAt($this->merchant, $this->creditor, $customer, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertCreated();
    $august = PayoutBatch::query()->where('reference', 'PB-20260824')->sole();

    expect($transaction->refresh()->payout_item_id)->not->toBeNull();

    // One non-cancelled batch per cutoff date.
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])->assertConflict();

    // The linked transaction cannot leak into a later batch.
    runOn('2026-08-29');
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-28'])
        ->assertCreated()
        ->assertJsonPath('data.customer_count', 0)
        ->assertJsonPath('data.total_laari', 0);

    // Cancelling the draft releases the links and frees the reference.
    $this->postJson("/api/admin/payout-batches/{$august->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    expect($transaction->refresh()->payout_item_id)->toBeNull();

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])
        ->assertCreated()
        ->assertJsonPath('data.customer_count', 1)
        ->assertJsonPath('data.total_laari', 10000);

    $rebuilt = PayoutBatch::query()
        ->where('reference', 'PB-20260824')
        ->where('state', '!=', PayoutBatchState::Cancelled)
        ->sole();

    expect($transaction->refresh()->payout_item_id)->toBe($rebuilt->items()->sole()->id);

    // Only a draft can be cancelled.
    approveBatch($rebuilt);
    $this->postJson("/api/admin/payout-batches/{$rebuilt->id}/cancel")->assertConflict();

    expect($rebuilt->refresh()->state)->toBe(PayoutBatchState::Approved);
});

it('refuses to build a batch whose cutoff is still in the future', function () {
    confirmTransactionAt($this->merchant, $this->creditor, payoutCustomer(), 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    // "Now" is 26 August at noon. A batch taken as of tomorrow would silently
    // miss every confirmation still to come before then — refused, and
    // nothing is created.
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-27'])
        ->assertUnprocessable();

    expect(PayoutBatch::query()->count())->toBe(0);

    // Today is not the future, even though the last second of it is: today's
    // cutoff is recorded as the clock, so the run an admin actually reaches
    // for builds, and records exactly what it swept.
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-26'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-20260826');

    expect(PayoutBatch::query()->sole()->cutoff_at->equalTo(CarbonImmutable::parse('2026-08-26T12:00:00+05:00')))->toBeTrue();

    // And once the refused day has passed, the same date builds.
    runOn('2026-08-28');

    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-27'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-20260827');
});

it('refuses a cutoff that is not a date at all', function () {
    foreach ([[], ['cutoff_date' => '26-08-2026'], ['cutoff_date' => '2026-08'], ['cutoff_date' => 'today']] as $payload) {
        $this->postJson('/api/admin/payout-batches', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cutoff_date');
    }

    expect(PayoutBatch::query()->count())->toBe(0);
});

it('excludes an eligible customer missing bank details and reports the money waiting', function () {
    // 15,000 laari confirmed (750,000 @ 200bp) — over the minimum, but the
    // customer never provided payout bank details.
    $noAccount = Customer::factory()->create();
    $banked = payoutCustomer();

    $stranded = confirmTransactionAt($this->merchant, $this->creditor, $noAccount, 750000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));
    confirmTransactionAt($this->merchant, $this->creditor, $banked, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    // The batch holds only the banked customer, and surfaces the skipped
    // count and total so admins can see money waiting on bank details.
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])
        ->assertCreated()
        ->assertJsonPath('data.customer_count', 1)
        ->assertJsonPath('data.total_laari', 10000)
        ->assertJsonPath('data.excluded_customer_count', 1)
        ->assertJsonPath('data.excluded_total_laari', 15000);

    $batch = PayoutBatch::query()->sole();

    // No item and no link: the money stays confirmed and unlinked, carrying
    // forward to the first build after the details arrive.
    expect($batch->items()->pluck('customer_id')->all())->toBe([$banked->id])
        ->and((int) $batch->excluded_customer_count)->toBe(1)
        ->and((int) $batch->excluded_total_laari)->toBe(15000)
        ->and($stranded->refresh()->payout_item_id)->toBeNull()
        ->and($stranded->state)->toBe(TransactionState::Confirmed);
});

it('returns every column the database defaulted, not nulls the client cannot parse', function () {
    // A model from create() carries only what was handed to it — anything
    // the DATABASE supplied is absent until the row is re-read. The row was
    // always correct; it was the RESPONSE that carried a null, so the build
    // looked like it had failed while the batch sat there, and the retry
    // answered "already exists".
    $response = $this->postJson('/api/admin/payout-batches', [
        'cutoff_date' => '2026-08-24',
    ])->assertCreated();

    expect($response->json('data.currency'))->toBe('MVR');

    // The same assertion the client's schema makes: nothing non-nullable is
    // null on the way out.
    foreach (['reference', 'state', 'currency', 'period_start', 'period_end'] as $key) {
        expect($response->json("data.{$key}"))->not->toBeNull();
    }
});
