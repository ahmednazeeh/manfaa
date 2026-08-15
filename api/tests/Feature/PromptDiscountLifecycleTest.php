<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Settlement\SettlementState;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\ReconciliationRun;
use App\Models\Settlement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ONE end-to-end HTTP journey for the PLAN §1 decision "Prompt-payment
 * discount — 5% off the PLATFORM FEE (never the customer's cashback) when a
 * merchant settles EVERYTHING outstanding and every line is under 10 days
 * old".
 *
 * Two merchants, one customer, one ledger, every step a real request through
 * the real routes:
 *
 *   A — PROMPT. Three sales accrue → the clock starts → on day 1 the manager
 *       previews Settle All and is shown the relief → they transfer the
 *       DISCOUNTED figure and submit the slip → the admin matches → all three
 *       rewards confirm, the receivable closes to zero, fee revenue is down by
 *       exactly the discount, the customer's cashback is untouched, and NO
 *       forgiveness journal exists: the batch was not short, it was discounted.
 *
 *   B — LATE. One sale, left for twelve days. The preview refuses the discount
 *       and SAYS WHY, the full amount is due, a client that tries to assert a
 *       discount in the request body is ignored, and the batch settles
 *       normally at full price.
 *
 * Both merchants are on the books at once on purpose: A earns the discount
 * while B is sitting on unsettled money, which is what proves "everything
 * outstanding" is scoped to the merchant asking.
 *
 * Every laari below is hand-derived from §4 and asserted against integer
 * arithmetic in the test itself — no figure here was copied out of a run.
 * The narrow cases (submit-time re-verification, partials, wallet funding,
 * caps, rejection) live in tests/Feature/PromptDiscount/. This file is the
 * journey they defend.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');

    $this->base = CarbonImmutable::parse('2026-08-01T10:00:00+00:00');
    Carbon::setTestNow($this->base);

    // Two stores at the §4 200bp tier → 75bp platform fee on both.
    $this->prompt = Merchant::factory()->create([
        'name' => 'Fresh Fish Co',
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    $this->late = Merchant::factory()->create([
        'name' => 'Slow Boat Cafe',
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);

    foreach ([$this->prompt, $this->late] as $merchant) {
        MerchantRate::factory()->for($merchant)->create([
            'rate_bp' => 200,
            'effective_from' => $this->base->subYear(),
            'effective_to' => null,
        ]);
    }

    $this->promptStaff = MerchantUser::factory()->for($this->prompt)->staff()->create();
    $this->promptManager = MerchantUser::factory()->for($this->prompt)->manager()->create();
    $this->lateOwner = MerchantUser::factory()->for($this->late)->owner()->create();

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->admin = AdminUser::factory()->create();

    // One customer shopping at both stores: the discount is a matter between
    // the platform and the merchant, and this is who must never notice it.
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A sale keyed in at the till, over HTTP, by one merchant's own user. */
function promptCredit(MerchantUser $user, string $invoiceNo, int $eligibleLaari, CarbonImmutable $occurredAt): TestResponse
{
    return test()->actingAs($user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => $invoiceNo,
            'eligible_amount' => $eligibleLaari,
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i:sP'),
        ]);
}

/** Debits posted against one settlement under a given journal description. */
function promptJournalDebits(int $settlementId, string $description): int
{
    return (int) DB::table('ledger_entries')
        ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
        ->where('ledger_journals.reference_type', 'settlement')
        ->where('ledger_journals.reference_id', $settlementId)
        ->where('ledger_journals.description', $description)
        ->sum('ledger_entries.debit_laari');
}

function promptJournalCount(int $settlementId, string $description): int
{
    return (int) DB::table('ledger_journals')
        ->where('reference_type', 'settlement')
        ->where('reference_id', $settlementId)
        ->where('description', $description)
        ->count();
}

it('walks the prompt-payment discount over HTTP: three credits → preview → discounted receipt → match → fee revenue down by exactly the discount, and a 12-day-old merchant pays in full', function () {
    // ── 0. The platform publishes where transfers must be sent ────────────
    $this->actingAs($this->superadmin, 'admin')
        ->postJson('/api/admin/platform/bank-accounts', [
            'bank_name' => 'Bank of Maldives',
            'account_no' => '7730000123456',
            'account_name' => 'Manfaa Pvt Ltd',
            'is_primary' => true,
            'active' => true,
        ])
        ->assertCreated();

    // ── 1. Three sales at the prompt store, one at the late store ─────────
    // HAND DERIVATION, §4 ceiling at 200bp cashback / 75bp fee:
    //   A INV-A-1  100,000 → 2,000 +   750 = 2,750
    //   A INV-A-2   50,000 → 1,000 +   375 = 1,375
    //   A INV-A-3  200,000 → 4,000 + 1,500 = 5,500
    //   A batch    350,000 → 7,000 + 2,625 = 9,625
    //   B INV-B-1  120,000 → 2,400 +   900 = 3,300
    expect(intdiv(200_000 * 200 + 9999, 10000))->toBe(4_000)
        ->and(intdiv(200_000 * 75 + 9999, 10000))->toBe(1_500)
        ->and(intdiv(120_000 * 75 + 9999, 10000))->toBe(900);

    $promptIds = [];

    foreach ([100_000, 50_000, 200_000] as $index => $eligible) {
        $promptIds[] = (int) promptCredit(
            $this->promptStaff,
            'INV-A-'.($index + 1),
            $eligible,
            $this->base->subHour()->addMinutes($index),
        )
            ->assertCreated()
            ->assertJsonPath('data.state', 'awaiting_validation')
            ->assertJsonPath('data.cashback_rate_percent', '2.00')
            ->assertJsonPath('data.platform_fee_percent', '0.75')
            ->json('data.id');
    }

    $lateId = (int) promptCredit($this->lateOwner, 'INV-B-1', 120_000, $this->base->subHour())
        ->assertCreated()
        ->assertJsonPath('data.cashback_laari', 2_400)
        ->assertJsonPath('data.fee_laari', 900)
        ->json('data.id');

    // §8: revenue and the receivable are recognised at ACCRUAL, before any
    // settlement exists — the discount will later reduce revenue that is
    // already on the books.
    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(9_625 + 3_300)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(2_625 + 900)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(7_000 + 2_400)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // ── 2. The refund window closes; the 15-day clock starts on all four ──
    Carbon::setTestNow($this->base->addDays(4));

    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    foreach ([...$promptIds, $lateId] as $id) {
        expect(Transaction::query()->findOrFail($id)->state)->toBe(TransactionState::PayableUnfunded);
    }

    // ── 3. Day 1: the prompt merchant previews Settle All ─────────────────
    Carbon::setTestNow($this->base->addDays(5));

    // HAND DERIVATION of the relief, §4 integer ceiling in the MERCHANT's
    // favour: 2,625 × 5% = 131.25 laari, which rounds UP to 132.
    //   intdiv(2,625 × 500 + 9,999, 10,000) = intdiv(1,322,499, 10,000) = 132
    //   due 9,625 − 132 = 9,493   (the 7,000 of cashback is untouched)
    // Integer arithmetic all the way down — the fraction is shown as the
    // REMAINDER, never as a float: 131 whole laari and 2,500 ten-thousandths
    // left over, so the ceiling is 132 and the merchant keeps the fraction.
    expect(intdiv(2_625 * 500, 10000))->toBe(131)
        ->and(2_625 * 500 % 10000)->toBe(2_500)
        ->and(intdiv(2_625 * 500 + 9999, 10000))->toBe(132);

    $preview = $this->actingAs($this->promptManager, 'merchant')
        ->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 3)
        ->assertJsonPath('data.cashback_total_laari', 7_000)
        ->assertJsonPath('data.fee_total_laari', 2_625)
        ->assertJsonPath('data.fee_gst_total_laari', 0)
        ->assertJsonPath('data.line_total_laari', 9_625)
        ->assertJsonPath('data.credit_applied_laari', 0)
        // The grant, with the reason and the terms it was granted under.
        ->assertJsonPath('data.discount.eligible', true)
        ->assertJsonPath('data.discount.reason_code', 'eligible')
        ->assertJsonPath('data.discount.rate_percent', '5.00')
        ->assertJsonPath('data.discount.max_age_days', 10)
        ->assertJsonPath('data.discount.discount_laari', 132)
        ->assertJsonPath('data.discount.fee_discount_laari', 132)
        ->assertJsonPath('data.discount.gst_relief_laari', 0)
        ->assertJsonPath('data.discount_laari', 132)
        ->assertJsonPath('data.discount_mvr', '1.32')
        ->assertJsonPath('data.amount_due_before_discount_laari', 9_625)
        ->assertJsonPath('data.amount_due_laari', 9_493)
        ->assertJsonPath('data.amount_due_mvr', '94.93')
        // Every line is one day old — comfortably inside the 10-day window.
        ->assertJsonPath('data.transactions.0.age_days', 1)
        ->assertJsonPath('data.transactions.2.age_days', 1)
        // What the merchant walks to the bank with (PLAN §1 receipt-first).
        ->assertJsonPath('data.payment_instructions.amount_due_laari', 9_493)
        ->assertJsonPath('data.payment_instructions.bank_account.account_no', '7730000123456')
        ->assertJsonPath('data.payment_instructions.reference_preview', 'ST-2026-00001')
        ->json('data');

    // The relief comes off the FEE and only the fee: cashback + fee − discount
    // is the figure to transfer, and the discount is a slice of the fee alone.
    expect($preview['amount_due_laari'])
        ->toBe($preview['cashback_total_laari'] + $preview['fee_total_laari'] - $preview['discount_laari'])
        ->and($preview['discount_laari'])->toBeLessThan($preview['fee_total_laari']);

    // The late merchant is sitting on 3,300 of unsettled money at this very
    // moment, and it does not cost the prompt merchant a laari: "everything
    // outstanding" means everything THIS merchant owes.
    expect(Transaction::query()->findOrFail($lateId)->state)->toBe(TransactionState::PayableUnfunded);

    // Previewing claims nothing — no draft, no reference burnt.
    expect(Settlement::query()->count())->toBe(0);

    // ── 4. They transfer the discounted figure and submit the slip ────────
    $promptSettlementId = (int) $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => $preview['amount_due_laari'],
        'bank_ref' => 'BML-PROMPT-1',
        'slip' => Slips::jpeg(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.reference', 'ST-2026-00001')
        ->assertJsonPath('data.cashback_total_laari', 7_000)
        ->assertJsonPath('data.fee_total_laari', 2_625)
        // The grant is STAMPED on the batch — the rate it was priced at, and
        // the machine reason — so what was granted survives a later change to
        // the platform setting.
        ->assertJsonPath('data.discount_laari', 132)
        ->assertJsonPath('data.discount_mvr', '1.32')
        ->assertJsonPath('data.discount_rate_percent', '5.00')
        ->assertJsonPath('data.discount_reason', 'eligible')
        ->assertJsonPath('data.amount_due_laari', 9_493)
        ->assertJsonPath('data.amount_received_laari', 0)
        ->assertJsonCount(3, 'data.lines')
        ->json('data.id');

    // Submitting only CLAIMS a transfer; nothing has funded these rewards yet.
    expect(promptJournalCount($promptSettlementId, 'Prompt-payment fee discount'))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_525);

    // ── 5. The admin queue matches the receipt ────────────────────────────
    $this->actingAs($this->admin, 'admin');

    $this->getJson('/api/admin/settlements?state=payment_review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $promptSettlementId)
        ->assertJsonPath('data.0.discount_laari', 132);

    $paymentId = (int) $this->getJson("/api/admin/settlements/{$promptSettlementId}")
        ->assertOk()
        ->assertJsonPath('data.payments.0.amount_laari', 9_493)
        ->assertJsonPath('data.payments.0.bank_ref', 'BML-PROMPT-1')
        ->assertJsonPath('data.payments.0.state', 'pending')
        ->json('data.payments.0.id');

    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_received_laari', 9_493)
        ->assertJsonPath('data.merchant_status.code', 'settled')
        ->assertJsonPath('data.lines.0.transaction.state', 'confirmed')
        ->assertJsonPath('data.lines.2.transaction.state', 'confirmed');

    // ── 6. Every line confirmed, on the discounted transfer alone ─────────
    $promptSettlement = Settlement::query()->findOrFail($promptSettlementId);

    expect($promptSettlement->state)->toBe(SettlementState::Settled)
        ->and($promptSettlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($promptSettlement->discount_posted_laari)->toBe(132);

    foreach ($promptIds as $id) {
        $transaction = Transaction::query()->findOrFail($id);

        expect($transaction->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at)->not->toBeNull();
    }

    $this->actingAs($this->promptManager, 'merchant')
        ->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 0)
        ->assertJsonPath('data.total.payable_laari', 0);

    // ── 7. The ledger: a sales discount on our own revenue ────────────────
    // 9,493 of cash + 132 of discount = the whole 9,625 this merchant accrued.
    // The discount is COVERED FUNDS, so there is no residue to forgive and no
    // wallet credit to park.
    expect(promptJournalDebits($promptSettlementId, 'Bank settlement received'))->toBe(9_493)
        ->and(promptJournalDebits($promptSettlementId, 'Prompt-payment fee discount'))->toBe(132)
        ->and(promptJournalCount($promptSettlementId, 'Prompt-payment fee discount'))->toBe(1)
        // NO forgiveness posting: the batch was never short.
        ->and(promptJournalCount($promptSettlementId, 'Settlement shortfall forgiven'))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and(DB::table('wallet_transactions')->count())->toBe(0);

    // The two legs, exactly as §8 specifies them: DR Platform Fee Revenue,
    // CR Merchant Receivable. Not bad debt, not platform-funded rewards.
    $discountJournal = DB::table('ledger_journals')
        ->where('reference_type', 'settlement')
        ->where('reference_id', $promptSettlementId)
        ->where('description', 'Prompt-payment fee discount')
        ->sole();

    $entries = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('journal_id', $discountJournal->id)
        ->get(['ledger_accounts.code', 'debit_laari', 'credit_laari']);

    expect($entries->map(fn ($entry) => [$entry->code, (int) $entry->debit_laari, (int) $entry->credit_laari])->all())
        ->toEqualCanonicalizing([
            [AccountCode::PlatformFeeRevenue->value, 132, 0],
            [AccountCode::MerchantReceivable->value, 0, 132],
        ]);

    // Fee revenue is down by exactly the discount, and by nothing else. The
    // remaining receivable is the late merchant's 3,300 to the laari — which
    // is what says the prompt merchant's own receivable closed to zero.
    expect($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_525 - 132)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(3_300)
        ->and($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(9_493)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // The customer never pays for the merchant's promptness: three rewards
    // confirm at their FULL value, and the fourth is still pending.
    $this->actingAs($this->customer, 'customer')
        ->withHeader('Referer', 'http://localhost')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 7_000)
        ->assertJsonPath('data.pending_laari', 2_400);

    // ══ ACT TWO — the merchant who waited ═════════════════════════════════
    // ── 8. Twelve days on the clock: the window has closed ────────────────
    Carbon::setTestNow($this->base->addDays(16));

    // 900 × 5% = 45 laari exactly — the relief this merchant will NOT get.
    expect(intdiv(900 * 500 + 9999, 10000))->toBe(45);

    $latePreview = $this->actingAs($this->lateOwner, 'merchant')
        ->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 1)
        ->assertJsonPath('data.line_total_laari', 3_300)
        // Refused, and the reason is an answer the merchant is entitled to:
        // the line is 12 days old against a 10-day window.
        ->assertJsonPath('data.discount.eligible', false)
        ->assertJsonPath('data.discount.reason_code', 'line_too_old')
        ->assertJsonPath('data.discount.rate_percent', '5.00')
        ->assertJsonPath('data.discount.max_age_days', 10)
        ->assertJsonPath('data.discount.discount_laari', 0)
        ->assertJsonPath('data.discount_laari', 0)
        ->assertJsonPath('data.transactions.0.age_days', 12)
        ->assertJsonPath('data.transactions.0.overdue', false)
        // The full amount is due: nothing came off.
        ->assertJsonPath('data.amount_due_before_discount_laari', 3_300)
        ->assertJsonPath('data.amount_due_laari', 3_300)
        ->assertJsonPath('data.amount_due_mvr', '33.00')
        ->assertJsonPath('data.payment_instructions.amount_due_laari', 3_300)
        ->assertJsonPath('data.payment_instructions.reference_preview', 'ST-2026-00002')
        ->json('data');

    expect($latePreview['amount_due_laari'])->toBe($latePreview['line_total_laari']);

    // ── 9. They settle normally, at full price ────────────────────────────
    // The request also asserts a discount of its own. Eligibility is decided
    // by the server against the locked rows and is never accepted as input —
    // the extra field is dropped on the floor.
    $lateSettlementId = (int) $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => 3_300,
        'bank_ref' => 'BML-LATE-1',
        'slip' => Slips::pdf(),
        'discount_laari' => 45,
        'discount' => ['eligible' => true, 'discount_laari' => 45],
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.reference', 'ST-2026-00002')
        ->assertJsonPath('data.discount_laari', 0)
        ->assertJsonPath('data.discount_rate_percent', null)
        ->assertJsonPath('data.discount_reason', 'line_too_old')
        ->assertJsonPath('data.amount_due_laari', 3_300)
        ->assertJsonCount(1, 'data.lines')
        ->json('data.id');

    $this->actingAs($this->admin, 'admin');

    $latePaymentId = (int) $this->getJson("/api/admin/settlements/{$lateSettlementId}")
        ->assertOk()
        ->assertJsonPath('data.payments.0.amount_laari', 3_300)
        ->json('data.payments.0.id');

    $this->postJson("/api/admin/payments/{$latePaymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.lines.0.transaction.state', 'confirmed');

    expect(Transaction::query()->findOrFail($lateId)->state)->toBe(TransactionState::Confirmed)
        ->and(Settlement::query()->findOrFail($lateSettlementId)->discount_posted_laari)->toBe(0)
        // No relief journal at all, and none of it hidden as forgiveness.
        ->and(promptJournalCount($lateSettlementId, 'Prompt-payment fee discount'))->toBe(0)
        ->and(promptJournalCount($lateSettlementId, 'Settlement shortfall forgiven'))->toBe(0)
        ->and(promptJournalDebits($lateSettlementId, 'Bank settlement received'))->toBe(3_300);

    // ── 10. The whole ledger, both merchants ──────────────────────────────
    // Cash 12,793 + the single 132 discount = 12,925 accrued. The receivable
    // is closed, the platform funded nothing, and the customers' 9,400 is
    // whole — the incentive was paid for out of our own fee revenue.
    expect($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(9_493 + 3_300)
        ->and($this->balances->accountBalance(AccountCode::SettlementCash) + 132)->toBe(12_925)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_525 - 132)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(9_400)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and(DB::table('wallet_transactions')->count())->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    $this->artisan('manfaa:reconcile')->assertExitCode(0);

    $run = ReconciliationRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe('ok')
        ->and($run->issues)->toBeNull()
        ->and($run->totals['receivable']['derived_laari'])->toBe(0)
        ->and($run->totals['receivable']['ledger_laari'])->toBe(0)
        // Derived and ledger agree on revenue AFTER the discount: the sales
        // discount is part of the platform's own books, not an unexplained gap.
        ->and($run->totals['revenue']['derived_laari'])->toBe(3_393)
        ->and($run->totals['revenue']['ledger_laari'])->toBe(3_393)
        ->and($run->totals['liability']['derived_laari'])->toBe(9_400)
        ->and($run->totals['liability']['ledger_laari'])->toBe(9_400);

    // And the customer, who was promised 9,400 laari (MVR 94.00) across the
    // two stores and got every laari of it — the merchants' fee is our
    // business with them, and it never reaches this number.
    $this->actingAs($this->customer, 'customer')
        ->withHeader('Referer', 'http://localhost')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 9_400)
        ->assertJsonPath('data.pending_laari', 0);
});
