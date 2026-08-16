<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\PayoutBatch;
use App\Models\ReconciliationRun;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Support\TransferSheet;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('slips');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Creates an active merchant on a standing 200bp rate (→ 75bp fee tier) with
 * an owner user, exactly as admin onboarding would leave it.
 *
 * @return array{0: Merchant, 1: MerchantUser}
 */
function lifecycleMerchant(): array
{
    $merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);

    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::parse('2026-01-01T00:00:00+05:00'),
        'effective_to' => null,
    ]);

    return [$merchant, MerchantUser::factory()->for($merchant)->owner()->create()];
}

/**
 * Keys one manual credit in over HTTP as the merchant user — the same wire
 * path a cashier uses — with occurred_at an hour before the frozen clock.
 */
function lifecycleCredit(MerchantUser $user, Customer $customer, string $invoiceNo, int $eligibleLaari): Transaction
{
    test()->actingAs($user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => $customer->customer_code,
            'invoice_no' => $invoiceNo,
            'eligible_amount' => $eligibleLaari,
            'occurred_at' => CarbonImmutable::now('Indian/Maldives')->subHour()->format('Y-m-d\TH:i:sP'),
        ])
        ->assertCreated();

    return Transaction::query()
        ->where('merchant_id', $user->merchant_id)
        ->where('invoice_no', $invoiceNo)
        ->sole();
}

/**
 * Merchant-side receipt-first settle-all (PLAN §1): select everything
 * eligible, state the transfer, attach the slip — one call, landing the
 * batch in payment_review with its receipt already attached.
 */
function lifecycleSettleAll(MerchantUser $user, int $amountLaari, string $bankRef): Settlement
{
    $response = test()->actingAs($user, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => $amountLaari,
            'bank_ref' => $bankRef,
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review');

    return Settlement::query()->findOrFail($response->json('data.id'));
}

/**
 * A further transfer against a batch still owed money — §7's partial
 * remainder, submitted with its own receipt.
 */
function lifecycleTopUp(MerchantUser $user, Settlement $settlement, int $amountLaari, string $bankRef): void
{
    test()->actingAs($user, 'merchant')
        ->post("/api/merchant/settlements/{$settlement->id}/receipts", [
            'amount' => $amountLaari,
            'bank_ref' => $bankRef,
            'slip' => Slips::pdf(),
        ])
        ->assertCreated();
}

/**
 * Admin-side: match the merchant's claimed transfer through the queue —
 * allocation, confirmation and postings all fire here.
 */
function lifecycleMatch(AdminUser $admin, Settlement $settlement, string $bankRef): Settlement
{
    $paymentId = $settlement->payments()->where('bank_ref', $bankRef)->sole()->id;

    test()->actingAs($admin, 'admin')
        ->postJson("/api/admin/payments/{$paymentId}/match")->assertOk();

    return $settlement->refresh();
}

/**
 * Sum of (cashback + fee + gst) still owed by one merchant, derived from the
 * transactions table alone — the §8 receivable a single merchant accounts for.
 */
function lifecycleReceivableFor(Merchant $merchant): int
{
    return (int) Transaction::query()
        ->where('merchant_id', $merchant->id)
        ->whereIn('state', ['tracked', 'awaiting_validation', 'payable_unfunded', 'on_hold'])
        ->sum(DB::raw('cashback_laari + fee_laari + fee_gst_laari'));
}

/**
 * Debit total of the journals referencing one settlement with the given
 * description — 0 when no such journal exists (same probe as AllocationTest).
 */
function lifecycleJournalDebits(int $settlementId, string $description): int
{
    return (int) DB::table('ledger_entries')
        ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
        ->where('ledger_journals.reference_type', 'settlement')
        ->where('ledger_journals.reference_id', $settlementId)
        ->where('ledger_journals.description', $description)
        ->sum('ledger_entries.debit_laari');
}

it('runs the full Phase 1 lifecycle: credit → sweep → settle → forgive → suspend → payout → write-off → reconcile', function () {
    $balances = new Balances;
    $this->seed(LedgerAccountSeeder::class);

    // ── (a) Seed: merchants A and B at 200bp, three customers, and two more
    // small merchants for the forgiveness and 90-day-default journeys; twelve
    // manual credits keyed in over Aug 1–3, all through the real HTTP path.
    [$merchantA, $userA] = lifecycleMerchant();
    [$merchantB, $userB] = lifecycleMerchant();
    [$merchantC, $userC] = lifecycleMerchant(); // small batch → forgiveness
    [$merchantD, $userD] = lifecycleMerchant(); // never pays → write-off

    $customer1 = Customer::factory()->create(['payout_bank' => 'bml', 'payout_account' => '7730000000101', 'payout_account_name' => 'Aminath Naseem']);
    $customer2 = Customer::factory()->create(['payout_bank' => 'bml', 'payout_account' => '7730000000102', 'payout_account_name' => 'Ibrahim Waheed']);
    $customer3 = Customer::factory()->create(['payout_bank' => 'bml', 'payout_account' => '7730000000103', 'payout_account_name' => 'Mariyam Shifa']);

    $august = CarbonImmutable::parse('2026-08-01T10:00:00+05:00');

    // Merchant A: exactly the §4 fixture — 2,750 / 1,375 / 5,500 / 2,200.
    $a = [];
    foreach ([100_000, 50_000, 200_000, 80_000] as $index => $eligible) {
        Carbon::setTestNow($august->addMinutes($index));
        $a[] = lifecycleCredit($userA, $customer1, 'INV-A'.($index + 1), $eligible);
    }

    expect(array_map(fn (Transaction $t) => [$t->cashback_laari, $t->fee_laari, $t->fee_gst_laari], $a))->toBe([
        [2_000, 750, 0],
        [1_000, 375, 0],
        [4_000, 1_500, 0],
        [1_600, 600, 0],
    ]);

    // Merchant B: five lines across all three customers — dues 4,435 /
    // 13,750 / 2,750 / 1,375 / 1,650 (total 23,960; ceiling rounds B1's fee
    // of 1,209.375 laari up to 1,210).
    $b = [];
    $bPlan = [[$customer1, 161_250], [$customer2, 500_000], [$customer3, 100_000], [$customer3, 50_000], [$customer2, 60_000]];
    foreach ($bPlan as $index => [$customer, $eligible]) {
        Carbon::setTestNow($august->addDay()->addMinutes($index));
        $b[] = lifecycleCredit($userB, $customer, 'INV-B'.($index + 1), $eligible);
    }

    expect($b[0]->cashback_laari)->toBe(3_225)
        ->and($b[0]->fee_laari)->toBe(1_210)
        ->and($b[1]->cashback_laari)->toBe(10_000);

    // Merchant C: two small lines (dues 550 + 825 = 1,375); merchant D one
    // line (due 2,750) that will never be paid.
    Carbon::setTestNow($august->addDays(2));
    $c1 = lifecycleCredit($userC, $customer3, 'INV-C1', 20_000);
    Carbon::setTestNow($august->addDays(2)->addMinute());
    $c2 = lifecycleCredit($userC, $customer3, 'INV-C2', 30_000);
    Carbon::setTestNow($august->addDays(2)->addMinutes(2));
    $d1 = lifecycleCredit($userD, $customer3, 'INV-D1', 100_000);

    // Twelve credits accrued: receivable 39,910 / liability 29,025 /
    // revenue 10,885 laari, one balanced journal each.
    expect(Transaction::query()->count())->toBe(12)
        ->and(Transaction::query()->where('state', TransactionState::AwaitingValidation)->count())->toBe(12)
        ->and($balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(39_910)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(29_025)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(10_885)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // ── (b) Day 0 of the clock: every validation window has elapsed by
    // Aug 6 noon; the sweep puts all twelve on the 15-day clock.
    Carbon::setTestNow($august->addDays(5)->setTime(12, 0)); // 2026-08-06T12:00+05
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    $expectedDueAt = CarbonImmutable::parse('2026-08-21T12:00:00+05:00');

    expect(Transaction::query()->where('state', TransactionState::PayableUnfunded)->count())->toBe(12);

    foreach (Transaction::query()->get() as $transaction) {
        expect($transaction->clock_start_at->equalTo(CarbonImmutable::now('UTC')))->toBeTrue()
            ->and($transaction->due_at->equalTo($transaction->clock_start_at->setTimezone('Indian/Maldives')->addDays(15)->utc()))->toBeTrue()
            ->and($transaction->due_at->equalTo($expectedDueAt))->toBeTrue();
    }

    $admin1 = AdminUser::factory()->create();
    $admin2 = AdminUser::factory()->create();

    // ── (c) Merchant A settles in full: settle-all picks up exactly the §4
    // batch and every line is one day old, so PLAN §1's prompt-payment
    // discount takes 5% off the FEE — 3,225 → intdiv(3225*500+9999, 10000)
    // = 162 — and A transfers 11,663 instead of 11,825. The customers' 8,600
    // of cashback is untouched, and matching confirms all four lines.
    Carbon::setTestNow($august->addDays(6)->setTime(11, 0)); // Aug 7
    $settlementA = lifecycleSettleAll($userA, 11_663, 'BML-A-11663');

    expect($settlementA->cashback_total_laari)->toBe(8_600)
        ->and($settlementA->fee_total_laari)->toBe(3_225)
        ->and($settlementA->fee_gst_total_laari)->toBe(0)
        ->and($settlementA->discount_laari)->toBe(162)
        ->and($settlementA->discount_rate_bp)->toBe(500)
        ->and($settlementA->discount_reason)->toBe('eligible')
        ->and($settlementA->amount_due_laari)->toBe(11_663)
        ->and($settlementA->due_at->equalTo($expectedDueAt))->toBeTrue()
        ->and($settlementA->lines()->count())->toBe(4);

    $settlementA = lifecycleMatch($admin1, $settlementA, 'BML-A-11663');

    expect($settlementA->state->value)->toBe('settled')
        ->and($settlementA->amount_received_laari)->toBe(11_663);

    foreach ($a as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at)->not->toBeNull()
            ->and($transaction->events()->where('to_state', 'confirmed')->sole()->reason_code)->toBe('settlement_allocated');
    }

    // Merchant A's receivable nets to zero — 11,663 of cash plus the 162
    // discount cover the whole 11,825, the discount comes out of OUR fee
    // revenue, and every journal still balances.
    expect(lifecycleReceivableFor($merchantA))->toBe(0)
        ->and(lifecycleJournalDebits($settlementA->id, 'Bank settlement received'))->toBe(11_663)
        ->and(lifecycleJournalDebits($settlementA->id, 'Prompt-payment fee discount'))->toBe(162)
        ->and($balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(39_910 - 11_825)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // ── (d) Merchant B pays 19,000 against 23,633 (23,960 of lines less a
    // 327 discount on its 6,535 of fee): oldest-first covers B1 and B2 in
    // whole (18,185, all of it B's own cash), B3 is unaffordable, and the 815
    // remainder is parked in the wallet — partially_settled. The 327 of
    // relief is NOT spent here: it was granted for clearing the board, and
    // this payment leaves three lines behind.
    Carbon::setTestNow($august->addDays(7)->setTime(11, 0)); // Aug 8
    $settlementB = lifecycleSettleAll($userB, 19_000, 'BML-B-19000');

    expect($settlementB->fee_total_laari)->toBe(6_535)
        ->and($settlementB->discount_laari)->toBe(327)
        ->and($settlementB->amount_due_laari)->toBe(23_633)
        ->and($settlementB->lines()->count())->toBe(5);

    $settlementB = lifecycleMatch($admin1, $settlementB, 'BML-B-19000');

    expect($settlementB->state->value)->toBe('partially_settled')
        ->and($settlementB->lines()->whereNotNull('allocated_at')->count())->toBe(2)
        ->and($b[0]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($b[1]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($b[2]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($b[3]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($b[4]->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    expect(lifecycleJournalDebits($settlementB->id, 'Bank settlement received'))->toBe(18_185)
        ->and(lifecycleJournalDebits($settlementB->id, 'Prompt-payment fee discount'))->toBe(0)
        ->and($settlementB->refresh()->discount_posted_laari)->toBe(0)
        ->and($merchantB->wallet()->sole()->balance_laari)->toBe(815)
        ->and($balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(815)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // ── (e) Forgiveness: merchant C owes 1,356 (1,375 of lines less a 19
    // discount on its 375 of fee) and pays 1,311 — 45 laari short, under
    // MVR 1 — so the whole batch settles, the platform books the 45 as
    // expense, and bad debt stays untouched at zero. A discount never
    // becomes forgiveness: the two are separate funding components and both
    // appear here.
    Carbon::setTestNow($august->addDays(8)->setTime(11, 0)); // Aug 9
    $settlementC = lifecycleSettleAll($userC, 1_311, 'BML-C-1311');

    expect($settlementC->discount_laari)->toBe(19)
        ->and($settlementC->amount_due_laari)->toBe(1_356);

    $settlementC = lifecycleMatch($admin1, $settlementC, 'BML-C-1311');

    expect($settlementC->state->value)->toBe('settled')
        ->and($settlementC->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($c1->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($c2->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and(lifecycleJournalDebits($settlementC->id, 'Settlement shortfall forgiven'))->toBe(45)
        ->and($balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(45)
        ->and($balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and(lifecycleReceivableFor($merchantC))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // ── (h·1) Day 16: B (three unpaid lines) and D (one) are past the
    // Aug 21 due date — automatic suspension, and cashback creation stops.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-22T09:00:00+05:00'));
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($merchantA->refresh()->status)->toBe('active')
        ->and($merchantB->refresh()->status)->toBe('suspended')
        ->and($merchantC->refresh()->status)->toBe('active')
        ->and($merchantD->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'suspended')->count())->toBe(2);

    // refresh() reloads the cached merchant relation — a real request would
    // resolve the user (and its suspended merchant) fresh from the database.
    $this->actingAs($userB->refresh(), 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => $customer1->customer_code,
            'invoice_no' => 'INV-B-SUSPENDED',
            'eligible_amount' => 100_000,
            'occurred_at' => CarbonImmutable::now('Indian/Maldives')->subHour()->format('Y-m-d\TH:i:sP'),
        ])
        ->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(12);

    // ── (h·2) Aug 25 — after the payout cutoff: B clears the remaining
    // 5,775 with 4,633 cash plus the 815 wallet credit and the 327 discount,
    // settles in full, and the next reinstatement run flips it back to
    // active. D stays suspended. The relief is released HERE — the match
    // that finishes the batch — and exactly once: 327 in total, never 654,
    // and the merchant's total outlay is still the discounted 23,633.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-25T10:00:00+05:00'));
    lifecycleTopUp($userB, $settlementB, 4_633, 'BML-B-4633');
    $settlementB = lifecycleMatch($admin1, $settlementB, 'BML-B-4633');

    expect($settlementB->state->value)->toBe('settled')
        ->and($settlementB->amount_received_laari)->toBe(23_633)
        ->and($merchantB->wallet()->sole()->balance_laari)->toBe(0)
        ->and(lifecycleJournalDebits($settlementB->id, 'Wallet settlement applied'))->toBe(815)
        ->and(lifecycleJournalDebits($settlementB->id, 'Bank settlement received'))->toBe(18_185 + 4_633)
        ->and(lifecycleJournalDebits($settlementB->id, 'Prompt-payment fee discount'))->toBe(327)
        ->and($settlementB->discount_posted_laari)->toBe(327)
        ->and(lifecycleReceivableFor($merchantB))->toBe(0);

    foreach ([$b[2], $b[3], $b[4]] as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at->equalTo(CarbonImmutable::now('UTC')))->toBeTrue();
    }

    $this->artisan('manfaa:reinstate')->assertExitCode(0);

    expect($merchantB->refresh()->status)->toBe('active')
        ->and($merchantD->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(1);

    // ── (f) Aug 26: build the payout batch to a cutoff of the 24th at 23:59
    // business time, so B3–B5 (confirmed Aug 25) roll into the next run:
    // customer 1 collects §4's 8,600 plus B1's 3,225 = exactly the §4 batch
    // total 11,825; customer 2 sits exactly AT the MVR 100 minimum with B2's
    // 10,000 (B5's 1,200 excluded by the cutoff); customer 3's pre-cutoff
    // 1,000 is under the minimum and carries forward.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-26T10:00:00+05:00'));

    $this->actingAs($admin1, 'admin')
        ->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-08-24'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-20260824')
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.customer_count', 2)
        ->assertJsonPath('data.total_laari', 21_825);

    $batch = PayoutBatch::query()->where('reference', 'PB-20260824')->sole();

    expect($batch->cutoff_at->equalTo(CarbonImmutable::parse('2026-08-24T23:59:59+05:00')))->toBeTrue();

    $item1 = $batch->items()->where('customer_id', $customer1->id)->sole();
    $item2 = $batch->items()->where('customer_id', $customer2->id)->sole();

    expect($item1->amount_laari)->toBe(11_825)
        ->and($item2->amount_laari)->toBe(10_000)
        ->and($batch->items()->where('customer_id', $customer3->id)->exists())->toBeFalse();

    // ── (g) One admin approves and the batch may go out; a second admin
    // finds nothing left to approve.
    $this->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.approved_by', $admin1->id);

    $this->actingAs($admin2, 'admin')
        ->postJson("/api/admin/payout-batches/{$batch->id}/approve")
        ->assertConflict();

    expect($batch->refresh()->approved_by)->toBe($admin1->id);

    // Export: the transfer sheet carries one row per payee, keyed by the MNF
    // idempotency key, and Amount Owed is a number — 118.25 for the §4
    // customer, straight from the stored integer.
    $export = $this->post("/api/admin/payout-batches/{$batch->id}/export");
    $export->assertOk();

    $sheet = TransferSheet::open($export->getContent());
    $row1 = TransferSheet::rowOf($sheet, $item1->idempotency_key);
    $row2 = TransferSheet::rowOf($sheet, $item2->idempotency_key);

    expect($sheet->getCell('D'.$row1)->getValue())->toBe('Aminath Naseem')
        ->and($sheet->getCell('E'.$row1)->getValue())->toBe('7730000000101')
        ->and($sheet->getCell('F'.$row1)->getValue())->toBe(118.25)
        ->and($sheet->getCell('D'.$row2)->getValue())->toBe('Ibrahim Waheed')
        ->and($sheet->getCell('E'.$row2)->getValue())->toBe('7730000000102')
        ->and($sheet->getCell('F'.$row2)->getValue())->toBe(100)
        ->and($batch->refresh()->state->value)->toBe('processing');

    // Results: customer 1's transfer comes back on the filled sheet with the
    // bank's reference in it; customer 2's was rejected, which the sheet has
    // no column for and an admin records by hand. Paid transactions move to
    // paid with one payoutSent journal for the stored item integer; the
    // failed item's transaction is unlinked and re-eligible; the liability
    // shrinks by exactly the paid 11,825.
    $liabilityBefore = $balances->naturalBalance(AccountCode::CustomerCashbackLiability);
    expect($liabilityBefore)->toBe(29_025);

    $this->post("/api/admin/payout-batches/{$batch->id}/import", [
        'file' => TransferSheet::filled($export->getContent(), [$item1->idempotency_key => 'BML-PAY-1']),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'sent');

    $this->postJson("/api/admin/payout-batches/{$batch->id}/items/{$item2->id}/mark-failed", [
        'failure_reason' => 'Account closed',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'partially_failed');

    foreach ([...$a, $b[0]] as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Paid)
            ->and($transaction->events()->where('to_state', 'paid')->sole()->reason_code)->toBe('payout_completed');
    }

    $payoutJournals = DB::table('ledger_journals')->where('reference_type', 'payout_item')->get();

    expect($payoutJournals)->toHaveCount(1)
        ->and($payoutJournals->first()->reference_id)->toBe($item1->id)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($liabilityBefore - 11_825)
        ->and($item2->refresh()->failure_reason)->toBe('Account closed')
        ->and($b[1]->refresh()->payout_item_id)->toBeNull()
        ->and($b[1]->state)->toBe(TransactionState::Confirmed);

    // The failed amount re-enters the very next build, joined by B5 which
    // confirmed after the August cutoff — customer 3 still under the minimum.
    // The chosen cutoff has to be a day the clock has already passed.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-26T10:00:00+05:00'));
    $this->postJson('/api/admin/payout-batches', ['cutoff_date' => '2026-09-24'])
        ->assertCreated()
        ->assertJsonPath('data.customer_count', 1)
        ->assertJsonPath('data.total_laari', 11_200);

    $september = PayoutBatch::query()->where('reference', 'PB-20260924')->sole();

    expect($september->items()->sole()->customer_id)->toBe($customer2->id);

    // ── (h·3) 90 days past due: merchant D never paid. The write-off books
    // the one place bad debt IS correct — DR Bad Debt 750 (the platform's
    // fee margin), DR Liability 2,000, CR Receivable 2,750 — balanced.
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-20T12:00:00+05:00'));
    $this->artisan('manfaa:write-off')->assertExitCode(0);

    expect($d1->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and($d1->reason_code)->toBe('merchant_default_90d');

    $writeOffJournal = DB::table('ledger_journals')
        ->where('reference_type', 'transaction')
        ->where('reference_id', $d1->id)
        ->where('description', 'Unsettled reward written off')
        ->sole();

    $entries = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('journal_id', $writeOffJournal->id)
        ->orderBy('ledger_accounts.code')
        ->get(['ledger_accounts.code', 'debit_laari', 'credit_laari']);

    expect($entries->map(fn ($entry) => [$entry->code, (int) $entry->debit_laari, (int) $entry->credit_laari])->all())->toEqualCanonicalizing([
        [AccountCode::MerchantReceivable->value, 0, 2_750],
        [AccountCode::CustomerCashbackLiability->value, 2_000, 0],
        [AccountCode::BadDebtExpense->value, 750, 0],
    ])
        ->and($balances->naturalBalance(AccountCode::BadDebtExpense))->toBe(750)
        ->and(MerchantNotice::query()->where('type', 'write_off')->sole()->merchant_id)->toBe($merchantD->id);

    // ── (i) Reconciliation: exit 0, an ok row, every journal balanced, and
    // the derived receivable / liability / revenue equal to the ledger.
    $this->artisan('manfaa:reconcile')->assertExitCode(0);

    $run = ReconciliationRun::query()->latest('id')->sole();

    expect($run->status)->toBe('ok')
        ->and($run->issues)->toBeNull()
        // 21 before the incentive existed, plus one prompt-payment discount
        // journal each for A, B and C.
        ->and($run->journals_checked)->toBe(24);

    // Independent derivation from the transactions and payout tables only.
    $derived = DB::table('transactions')->selectRaw(<<<'SQL'
        COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari) FILTER (
            WHERE state IN ('tracked', 'awaiting_validation', 'payable_unfunded', 'on_hold')
        ), 0) AS receivable_laari,
        COALESCE(SUM(cashback_laari) FILTER (
            WHERE state IN ('tracked', 'awaiting_validation', 'payable_unfunded', 'on_hold', 'confirmed')
        ), 0) AS liability_laari,
        COALESCE(SUM(fee_laari) FILTER (WHERE state <> 'reversed'), 0) AS revenue_laari,
        COALESCE(SUM(cashback_laari) FILTER (WHERE state = 'paid'), 0) AS paid_laari
        SQL)->first();

    $paidItems = (int) DB::table('payout_items')->where('state', 'paid')->sum('amount_laari');

    expect((int) $derived->receivable_laari)->toBe(0)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and((int) $derived->liability_laari)->toBe(15_200)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(15_200)
        // Accrued fee revenue is still 10,885; the ledger carries it net of
        // the three prompt-payment discounts (162 + 327 + 19 = 508), which
        // are a sales discount on our own revenue and reduce it for good.
        ->and((int) $derived->revenue_laari)->toBe(10_885)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(10_885 - 508)
        ->and((int) $derived->paid_laari)->toBe(11_825)
        ->and($paidItems)->toBe(11_825);

    // The final trial balance, to the laari: cash holds everything received
    // minus the payout; wallet and GST are flat; the two expense accounts
    // carry exactly the forgiven 45 and the defaulted margin 750.
    $trial = collect($balances->trialBalance())->map(fn (array $row) => $row['balance_laari'])->all();

    // Cash received: A 11,663 + B (19,000 + 4,633) + C 1,311 = 36,607 —
    // exactly 508 less than the 37,115 the same batches owed before the
    // prompt-payment discount, and the same 508 the fee revenue gave up.
    expect($trial)->toBe([
        1000 => 36_607 - 11_825,  // Settlement Cash
        1100 => 0,                // Merchant Receivable
        2100 => -15_200,          // Customer Cashback Liability
        2200 => 0,                // Merchant Wallet Balance
        2300 => 0,                // Fee GST Payable
        4100 => -(10_885 - 508),  // Platform Fee Revenue, net of discounts
        5100 => 45,               // Platform-Funded Rewards
        5900 => 750,              // Bad Debt Expense
    ])
        ->and(array_sum($trial))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // No silent state mutation anywhere in the lifecycle: every hop on all
    // twelve transactions is evidenced by exactly one event row.
    // 12 created + 12 awaiting_validation + 12 payable + 11 confirmed
    // + 5 paid + 1 written_off = 53.
    expect(TransactionEvent::query()->count())->toBe(53)
        ->and(Transaction::query()->count())->toBe(12);
});
