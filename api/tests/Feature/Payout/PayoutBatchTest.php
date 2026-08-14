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
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // A fixed instant after the August 2026 cutoff (the 24th, §13) — a batch
    // can only be built once its period's cutoff has passed.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-26T12:00:00+05:00'));

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
 * The August 2026 cutoff instant: the 24th at 23:59:59 business time (§13).
 */
function augustCutoff(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-24T23:59:59+05:00');
}

/**
 * Moves the clock past the September 2026 cutoff so that period can build.
 */
function afterSeptemberCutoff(): void
{
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-26T12:00:00+05:00'));
}

/**
 * A customer with complete §13 bank details — the default fixture customer
 * for payout tests, since a detail-less customer is excluded from batches.
 */
function payoutCustomer(array $attributes = []): Customer
{
    return Customer::factory()->create($attributes + [
        'payout_bank' => 'BML',
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

function dualApprove(PayoutBatch $batch): PayoutBatch
{
    $approvals = app(ApprovalService::class);
    $approvals->approve($batch, AdminUser::factory()->create());
    $approvals->approve($batch, AdminUser::factory()->create());

    return $batch->refresh();
}

it('includes confirmations up to the cutoff instant and rolls later ones to next month', function () {
    $inside = payoutCustomer();
    $outside = payoutCustomer();
    $viaColumn = payoutCustomer();

    // 24th 23:59 business time is in; 25th 00:01 is out (§13).
    $inTx = confirmTransactionAt($this->merchant, $this->creditor, $inside, 500000, CarbonImmutable::parse('2026-08-24T23:59:00+05:00'));
    $outTx = confirmTransactionAt($this->merchant, $this->creditor, $outside, 500000, CarbonImmutable::parse('2026-08-25T00:01:00+05:00'));

    // Settlement's confirmed_at column wins over a (deliberately absurd)
    // event timestamp — both recording styles are supported.
    $columnTx = confirmTransactionAt($this->merchant, $this->creditor, $viaColumn, 500000, CarbonImmutable::parse('2026-08-24T12:00:00+05:00'), viaConfirmedAtColumn: true);

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-2026-08')
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.customer_count', 2)
        ->assertJsonPath('data.total_laari', 20000);

    $batch = PayoutBatch::query()->sole();

    expect($batch->cutoff_at->equalTo(augustCutoff()))->toBeTrue()
        ->and($batch->items()->pluck('customer_id')->all())
        ->toEqualCanonicalizing([$inside->id, $viaColumn->id]);

    expect($inTx->refresh()->payout_item_id)->not->toBeNull()
        ->and($columnTx->refresh()->payout_item_id)->not->toBeNull()
        ->and($outTx->refresh()->payout_item_id)->toBeNull();
});

it('excludes customers below MVR 100 and includes them next period once topped up', function () {
    $below = payoutCustomer();
    $at = payoutCustomer();

    // 499,950 @ 200bp ceils to 9,999 laari — one short of the minimum.
    confirmTransactionAt($this->merchant, $this->creditor, $below, 499950, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));
    // 500,000 @ 200bp is exactly 10,000 laari — included.
    confirmTransactionAt($this->merchant, $this->creditor, $at, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertCreated();

    $august = PayoutBatch::query()->where('reference', 'PB-2026-08')->sole();

    expect($august->items()->count())->toBe(1)
        ->and($august->items()->sole()->customer_id)->toBe($at->id)
        ->and($august->items()->sole()->amount_laari)->toBe(10000);

    // The 9,999 carried forward automatically: a 100-laari top-up confirmed
    // in September clears the bar, and both transactions pay out together.
    confirmTransactionAt($this->merchant, $this->creditor, $below, 5000, CarbonImmutable::parse('2026-09-10T10:00:00+05:00'));

    afterSeptemberCutoff();
    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 9])->assertCreated();

    $september = PayoutBatch::query()->where('reference', 'PB-2026-09')->sole();
    $item = $september->items()->sole();

    expect($item->customer_id)->toBe($below->id)
        ->and($item->amount_laari)->toBe(9999 + 100)
        ->and(Transaction::query()->where('customer_id', $below->id)->whereNull('payout_item_id')->count())->toBe(0);
});

it('requires two distinct admins to approve and refuses approval of a non-draft batch', function () {
    confirmTransactionAt($this->merchant, $this->creditor, payoutCustomer(), 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    // First approval records the approver but does not move the state.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.approved_by_first', $this->admin->id);

    // The same admin approving twice is refused in the domain.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertUnprocessable();

    expect($batch->refresh()->state)->toBe(PayoutBatchState::Draft)
        ->and($batch->approved_by_second)->toBeNull();

    // A different admin completes the dual approval.
    $second = AdminUser::factory()->create();
    $this->actingAs($second, 'admin')
        ->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.approved_by_second', $second->id);

    // Approving a non-draft batch is refused.
    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertConflict();
});

it('exports the bank file only from approved, with parseable amounts and injection-proofed cells', function () {
    $customer = Customer::factory()->create([
        'payout_bank' => 'BML',
        'payout_account' => '7730000000001',
        'payout_account_name' => '=cmd',
    ]);

    // 591,250 @ 200bp ceils to exactly 11,825 laari = MVR 118.25.
    confirmTransactionAt($this->merchant, $this->creditor, $customer, 591250, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    // A draft batch has no bank file — approval first.
    $this->post("/api/admin/payout-batches/{$batch->id}/export", [], ['Accept' => 'application/json'])
        ->assertConflict();

    dualApprove($batch);

    $response = $this->post("/api/admin/payout-batches/{$batch->id}/export");
    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('PB-2026-08.csv');

    $item = $batch->items()->sole();
    $lines = explode("\n", trim($response->getContent()));

    // Amounts are decimal MVR without thousands separators, and a cell
    // starting with = is prefixed with a single quote so no spreadsheet
    // executes it as a formula.
    expect($lines[0])->toBe('item_id,account_no,account_name,bank_name,amount_mvr')
        ->and($lines[1])->toBe("{$item->id},7730000000001,'=cmd,BML,118.25");

    $batch->refresh();

    expect($batch->state)->toBe(PayoutBatchState::Processing)
        ->and($batch->exported_at)->not->toBeNull()
        ->and($item->refresh()->state)->toBe(PayoutItemState::Sent);

    // A lost download is recoverable: while the batch is processing and no
    // result has arrived, re-export returns the byte-identical file without
    // touching state.
    $again = $this->post("/api/admin/payout-batches/{$batch->id}/export");
    $again->assertOk();

    expect($again->getContent())->toBe($response->getContent())
        ->and($batch->refresh()->state)->toBe(PayoutBatchState::Processing)
        ->and($batch->items()->where('state', PayoutItemState::Sent)->count())->toBe(1);

    // Once the bank's results start landing, a fresh file would diverge from
    // the one the bank acted on — export closes.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('results.csv', "item_id,status,reference,failure_reason\n{$item->id},paid,BML-R1,\n"),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->post("/api/admin/payout-batches/{$batch->id}/export", [], ['Accept' => 'application/json'])
        ->assertConflict();
});

it('applies a mixed result file: pays, posts, re-queues failures, and re-imports idempotently', function () {
    $paidCustomer = Customer::factory()->create(['payout_bank' => 'BML', 'payout_account' => '7730000000001', 'payout_account_name' => 'Aishath Ali']);
    $failedCustomer = Customer::factory()->create(['payout_bank' => 'BML', 'payout_account' => '7730000000002', 'payout_account_name' => 'Hassan Manik']);

    $confirmedAt = CarbonImmutable::parse('2026-08-20T10:00:00+05:00');
    $paidTxA = confirmTransactionAt($this->merchant, $this->creditor, $paidCustomer, 500000, $confirmedAt);   // 10,000
    $paidTxB = confirmTransactionAt($this->merchant, $this->creditor, $paidCustomer, 100000, $confirmedAt);   // 2,000
    $failedTx = confirmTransactionAt($this->merchant, $this->creditor, $failedCustomer, 500000, $confirmedAt); // 10,000

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    // A result file cannot land before the bank file went out.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('results.csv', "item_id,status\n1,paid\n"),
    ], ['Accept' => 'application/json'])->assertConflict();

    dualApprove($batch);
    app(BankFileExporter::class)->export($batch);

    $paidItem = $batch->items()->where('customer_id', $paidCustomer->id)->sole();
    $failedItem = $batch->items()->where('customer_id', $failedCustomer->id)->sole();

    expect($paidItem->amount_laari)->toBe(12000)->and($failedItem->amount_laari)->toBe(10000);

    $balances = new Balances;
    $liabilityBefore = $balances->naturalBalance(AccountCode::CustomerCashbackLiability);
    expect($liabilityBefore)->toBe(22000);

    $csv = implode("\n", [
        'item_id,status,reference,failure_reason',
        "{$paidItem->id},paid,BML-REF-1,",
        "{$failedItem->id},failed,,Account closed",
    ]);

    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('results.csv', $csv),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'partially_failed');

    // Paid: both transactions moved confirmed → paid through the state
    // machine, with the event evidencing actor and reason.
    foreach ([$paidTxA, $paidTxB] as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Paid);

        $event = $transaction->events()->where('to_state', 'paid')->get();
        expect($event)->toHaveCount(1)
            ->and($event->first()->actor_type)->toBe('system')
            ->and($event->first()->reason_code)->toBe('payout_completed');
    }

    // Exactly ONE payoutSent journal, for the item's stored sum — and the
    // liability shrank by exactly that integer.
    $payoutJournals = DB::table('ledger_journals')->where('reference_type', 'payout_item')->get();

    expect($payoutJournals)->toHaveCount(1)
        ->and($payoutJournals->first()->reference_id)->toBe($paidItem->id)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($liabilityBefore - 12000);

    // Failed: the item carries the reason; its transaction is unlinked and
    // still confirmed, ready for the next period.
    expect($paidItem->refresh()->state)->toBe(PayoutItemState::Paid)
        ->and($paidItem->bank_reference)->toBe('BML-REF-1')
        ->and($failedItem->refresh()->state)->toBe(PayoutItemState::Failed)
        ->and($failedItem->failure_reason)->toBe('Account closed')
        ->and($failedTx->refresh()->payout_item_id)->toBeNull()
        ->and($failedTx->state)->toBe(TransactionState::Confirmed);

    // Re-importing the same file changes nothing: no second journal, no
    // second paid event, no state churn.
    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('results.csv', $csv),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'partially_failed');

    expect(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1)
        ->and($paidTxA->refresh()->events()->where('to_state', 'paid')->count())->toBe(1)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($liabilityBefore - 12000)
        ->and($failedTx->refresh()->payout_item_id)->toBeNull();

    // The failed customer's amount re-enters the very next build.
    afterSeptemberCutoff();
    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 9])->assertCreated();

    $september = PayoutBatch::query()->where('reference', 'PB-2026-09')->sole();
    $requeued = $september->items()->sole();

    expect($requeued->customer_id)->toBe($failedCustomer->id)
        ->and($requeued->amount_laari)->toBe(10000)
        ->and($failedTx->refresh()->payout_item_id)->toBe($requeued->id);
});

it('never includes a linked transaction twice, and cancel unlinks so the period can be rebuilt', function () {
    $customer = payoutCustomer();
    $transaction = confirmTransactionAt($this->merchant, $this->creditor, $customer, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertCreated();
    $august = PayoutBatch::query()->where('reference', 'PB-2026-08')->sole();

    expect($transaction->refresh()->payout_item_id)->not->toBeNull();

    // One non-cancelled batch per period.
    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertConflict();

    // The linked transaction cannot leak into another period's batch.
    afterSeptemberCutoff();
    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 9])
        ->assertCreated()
        ->assertJsonPath('data.customer_count', 0)
        ->assertJsonPath('data.total_laari', 0);

    // Cancelling the draft releases the links and frees the reference.
    $this->postJson("/api/admin/payout-batches/{$august->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    expect($transaction->refresh()->payout_item_id)->toBeNull();

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])
        ->assertCreated()
        ->assertJsonPath('data.customer_count', 1)
        ->assertJsonPath('data.total_laari', 10000);

    $rebuilt = PayoutBatch::query()
        ->where('reference', 'PB-2026-08')
        ->where('state', '!=', PayoutBatchState::Cancelled)
        ->sole();

    expect($transaction->refresh()->payout_item_id)->toBe($rebuilt->items()->sole()->id);

    // Only a draft can be cancelled.
    dualApprove($rebuilt);
    $this->postJson("/api/admin/payout-batches/{$rebuilt->id}/cancel")->assertConflict();

    expect($rebuilt->refresh()->state)->toBe(PayoutBatchState::Approved);
});

it('rejects a result file referencing another batch\'s items without changing anything', function () {
    $customerA = payoutCustomer();
    confirmTransactionAt($this->merchant, $this->creditor, $customerA, 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    dualApprove($batch);
    app(BankFileExporter::class)->export($batch);

    $item = $batch->items()->sole();
    $foreignId = $item->id + 999;

    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('results.csv', "item_id,status\n{$item->id},paid\n{$foreignId},paid\n"),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    // The whole file was rejected — even the valid row changed nothing.
    expect($item->refresh()->state)->toBe(PayoutItemState::Sent)
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(0);
});

it('refuses to build a batch whose cutoff instant is still in the future', function () {
    confirmTransactionAt($this->merchant, $this->creditor, payoutCustomer(), 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    // "Now" is 26 August — September's cutoff (the 24th, §13) has not
    // happened yet, so a September build would silently miss every
    // confirmation still to come. It is refused and nothing is created.
    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 9])
        ->assertUnprocessable();

    expect(PayoutBatch::query()->count())->toBe(0);

    // The moment the cutoff has passed, the same request builds fine.
    afterSeptemberCutoff();
    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 9])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-2026-09');
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
    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])
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

it('imports a result file carrying a UTF-8 BOM and leading blank lines', function () {
    confirmTransactionAt($this->merchant, $this->creditor, payoutCustomer(), 500000, CarbonImmutable::parse('2026-08-20T10:00:00+05:00'));

    $this->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])->assertCreated();
    $batch = PayoutBatch::query()->sole();

    dualApprove($batch);
    app(BankFileExporter::class)->export($batch);

    $item = $batch->items()->sole();

    // Exactly what some banks emit: a UTF-8 BOM, then blank lines, then the
    // header — all tolerated, none meaningful.
    $csv = "\u{FEFF}\r\n\r\nitem_id,status,reference,failure_reason\r\n{$item->id},paid,BML-BOM-1,\r\n";

    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('results.csv', $csv),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed');

    expect($item->refresh()->state)->toBe(PayoutItemState::Paid)
        ->and($item->bank_reference)->toBe('BML-BOM-1')
        ->and(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1);
});
