<?php

declare(strict_types=1);

use App\Domain\Adjustment\BackdatedIrreversibleException;
use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\SlipStorage;
use App\Models\Adjustment;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\ReconciliationRun;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ONE end-to-end HTTP journey per PLAN §1 decision (Task #23) — every step a
 * real request through the real routes, every integer hand-derived from §4:
 *
 *  1. "Settlement flow — receipt-first, merchant-driven": superadmin puts a
 *     receiving account on the platform → staff credits four sales → the
 *     clock sweep makes them payable → the MANAGER previews (amount due +
 *     platform bank account + reference) → staff is refused → the manager
 *     transfers and SUBMITS the slip, landing directly in payment_review →
 *     the admin queue shows the batch and STREAMS the slip → the admin
 *     REJECTS with a reason → every line releases and the merchant is told
 *     why → the merchant submits a NEW batch, 45 laari short → the admin
 *     matches → §7 forgiveness absorbs the sub-MVR-1 gap, every customer
 *     reward confirms, and the ledger reconciles to the laari.
 *
 *  2. "Backdated credits — no admin approval, immediately payable,
 *     merchant-irreversible": a sale dated 30 days back skips on_hold and
 *     the refund window entirely, is settleable the same minute it is keyed
 *     in, and the vendor's own /v1 reversal is refused 409.
 *
 * The narrow, adversarial cases (slip signature matrix, duplicate bank refs,
 * absence of the old draft-then-submit routes) live in
 * tests/Feature/ReceiptSettlement/. This file is the journey they defend.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');

    $this->base = CarbonImmutable::parse('2026-08-01T10:00:00+00:00');
    Carbon::setTestNow($this->base);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200, // §4 tier: 200bp cashback → 75bp platform fee
        'effective_from' => $this->base->subYear(),
        'effective_to' => null,
    ]);

    // The three-tier staff model (PLAN §1): staff key in credits, the
    // manager settles, and neither touches the platform's own accounts.
    $this->manager = MerchantUser::factory()->for($this->merchant)->manager()->create();
    $this->staff = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->admin = AdminUser::factory()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A sale keyed in at the till by STAFF, over HTTP. */
function receiptLifecycleCredit(string $invoiceNo, int $eligibleLaari, CarbonImmutable $occurredAt): TestResponse
{
    return test()->actingAs(test()->staff, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => $invoiceNo,
            'eligible_amount' => $eligibleLaari,
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i:sP'),
        ]);
}

/**
 * A vendor request through the real wire path. actingAs() leaves users on the
 * session guards and config('sanctum.guard') consults those BEFORE the bearer
 * token, so the resolved guards are dropped first — a till request arrives
 * with no panel session, and the test must look exactly like one.
 *
 * @param  array<string, mixed>  $payload
 */
function receiptLifecycleVendorPost(string $path, array $payload): TestResponse
{
    app('auth')->forgetGuards();
    test()->flushHeaders();

    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->vendorToken,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson($path, $payload);
}

it('walks the receipt-first settlement lifecycle over HTTP: credits → preview → slip → reject → resubmit → match → forgiveness', function () {
    // ── 1. SUPERADMIN publishes where merchants must send transfers ───────
    // Merchant payment instructions are read off the active primary account;
    // writing it is superadmin-only, and no merchant role can touch it.
    $this->actingAs($this->superadmin, 'admin')
        ->postJson('/api/admin/platform/bank-accounts', [
            'bank_name' => 'Bank of Maldives',
            'account_no' => '7730000123456',
            'account_name' => 'Manfaa Pvt Ltd',
            'is_primary' => true,
            'active' => true,
        ])
        ->assertCreated();

    // ── 2. STAFF credits four sales over HTTP (the §4 batch fixture) ──────
    // HAND DERIVATION at 200bp cashback / 75bp fee, ceiling per §4:
    //   INV-1001 100,000 → 2,000 + 750 = 2,750
    //   INV-1002  50,000 → 1,000 + 375 = 1,375
    //   INV-1003 200,000 → 4,000 + 1,500 = 5,500
    //   INV-1004  80,000 → 1,600 + 600 = 2,200
    //   BATCH    430,000 → 8,600 + 3,225 = 11,825
    expect(intdiv(100000 * 200 + 9999, 10000))->toBe(2000)
        ->and(intdiv(100000 * 75 + 9999, 10000))->toBe(750);

    $ids = [];

    foreach ([100000, 50000, 200000, 80000] as $index => $eligible) {
        $ids[] = (int) receiptLifecycleCredit(
            'INV-100'.($index + 1),
            $eligible,
            // Inside the 3-day refund window: the ordinary path, NOT backdated.
            $this->base->subHour()->addMinutes($index),
        )
            ->assertCreated()
            ->assertJsonPath('data.state', 'awaiting_validation')
            ->assertJsonPath('data.backdated', false)
            ->assertJsonPath('data.rate_bp', 200)
            ->assertJsonPath('data.fee_bp', 75)
            ->json('data.id');
    }

    // Nothing is settleable yet — the refund window has not closed.
    $this->actingAs($this->manager, 'merchant')
        ->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 0);

    $this->getJson('/api/merchant/settlements/preview?settle_all=1')->assertUnprocessable();

    // The accrual is on the ledger from the sale, not from the settlement
    // (§8: revenue is recognised on accrual, matching the receivable).
    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11_825)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(8_600)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // ── 3. The validation window closes; the 15-day clock starts ──────────
    Carbon::setTestNow($this->base->addDays(4));

    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    foreach ($ids as $id) {
        expect(Transaction::query()->findOrFail($id)->state)->toBe(TransactionState::PayableUnfunded);
    }

    // ── 4. The MANAGER previews: what is owed, and where to send it ───────
    $preview = $this->actingAs($this->manager, 'merchant')
        ->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 4)
        ->assertJsonPath('data.line_total_laari', 11_825)
        ->assertJsonPath('data.credit_applied_laari', 0)
        // PLAN §1 prompt-payment discount at the shipped defaults: the batch
        // covers everything outstanding and every line is 1 day old, so 5%
        // comes off the FEE TOTAL and nothing off the cashback.
        //   fee 3,225 → intdiv(3225 * 500 + 9999, 10000) = 162
        //   due 11,825 − 162 = 11,663
        ->assertJsonPath('data.discount.eligible', true)
        ->assertJsonPath('data.discount_laari', 162)
        ->assertJsonPath('data.amount_due_laari', 11_663)
        ->assertJsonPath('data.amount_due_mvr', '116.63')
        // PLAN §1: "see amount due + platform bank account (copy button) +
        // reference" — the whole point of previewing before walking to the bank.
        ->assertJsonPath('data.payment_instructions.bank_account.bank_name', 'Bank of Maldives')
        ->assertJsonPath('data.payment_instructions.bank_account.account_no', '7730000123456')
        ->assertJsonPath('data.payment_instructions.bank_account.account_name', 'Manfaa Pvt Ltd')
        ->assertJsonPath('data.payment_instructions.needs_configuration', false)
        ->assertJsonPath('data.payment_instructions.reference_preview', 'ST-2026-00001')
        ->assertJsonPath('data.payment_instructions.reference_is_final', false)
        ->json('data');

    // Previewing claims NOTHING: no draft, no reference burnt, no line held.
    expect(Settlement::query()->count())->toBe(0);

    // ── 5. STAFF cannot claim a transfer; the manager can ─────────────────
    $this->actingAs($this->staff, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => $preview['amount_due_laari'],
            'bank_ref' => 'BML-STAFF-TRY',
            'slip' => Slips::jpeg(),
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'manager_required');

    expect(Settlement::query()->count())->toBe(0);

    // ── 6. The manager transfers at their bank, then SUBMITS the slip ─────
    // The single act that creates a settlement at all — it lands directly in
    // payment_review, never at an awaiting_payment dead end.
    $first = $this->actingAs($this->manager, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => $preview['amount_due_laari'],
            'bank_ref' => 'BML-88421',
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.reference', 'ST-2026-00001')
        ->assertJsonPath('data.amount_due_laari', 11_663)
        ->assertJsonPath('data.discount_laari', 162)
        ->assertJsonPath('data.amount_received_laari', 0)
        ->assertJsonPath('data.merchant_status.code', 'verifying')
        ->assertJsonCount(4, 'data.lines')
        ->json('data');

    $firstId = (int) $first['id'];

    expect($first['amount_due_laari'])->toBe($preview['amount_due_laari'])
        ->and($first['reference'])->toBe($preview['payment_instructions']['reference_preview']);

    // Every line is claimed: a second batch has nothing left to take.
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')->assertUnprocessable();

    // They still read as outstanding, and that is the honest answer: the
    // transfer is unverified, so nothing has funded these rewards yet. What
    // clears them is the admin's match, not the merchant's claim.
    $this->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4)
        ->assertJsonPath('data.total.payable_laari', 11_825);

    // ── 7. The ADMIN queue shows the batch, with its evidence ─────────────
    $this->actingAs($this->admin, 'admin');

    $this->getJson('/api/admin/settlements?state=payment_review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $firstId);

    $detail = $this->getJson("/api/admin/settlements/{$firstId}")
        ->assertOk()
        ->assertJsonPath('data.payments.0.amount_laari', 11_663)
        ->assertJsonPath('data.payments.0.bank_ref', 'BML-88421')
        ->assertJsonPath('data.payments.0.state', 'pending')
        ->assertJsonPath('data.payments.0.has_slip', true)
        // The mime is what the BYTES said, never the client's Content-Type.
        ->assertJsonPath('data.payments.0.slip_mime', 'image/jpeg')
        ->assertJsonPath('data.payments.0.uploaded_by', $this->manager->id)
        ->json('data');

    $slip = $this->get("/api/admin/settlements/{$firstId}/slip")->assertOk();

    expect($slip->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($slip->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($slip->streamedContent())->toStartWith("\xFF\xD8\xFF");

    // ── 8. The admin cannot verify the transfer and REJECTS it ────────────
    $this->postJson("/api/admin/settlements/{$firstId}/reject", [
        'reason' => 'No transfer with reference BML-88421 reached the account on 5 August.',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    $rejected = Settlement::query()->findOrFail($firstId);

    expect($rejected->state)->toBe(SettlementState::Cancelled)
        ->and($rejected->lines()->count())->toBe(0);

    // The queue drains: a refused batch is not left sitting in review.
    $this->getJson('/api/admin/settlements?state=payment_review')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Nothing was ever allocated, so no customer's reward moved and the
    // ledger never saw the claimed cash.
    foreach ($ids as $id) {
        expect(Transaction::query()->findOrFail($id)->state)->toBe(TransactionState::PayableUnfunded);
    }

    expect($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11_825)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // The refused slip is KEPT — append-only history: the evidence of what
    // was offered survives the refusal.
    $refusedPayment = SettlementPayment::query()->sole();

    expect($refusedPayment->state)->toBe('rejected')
        ->and($refusedPayment->rejected_by)->toBe($this->admin->id)
        ->and(Storage::disk(SlipStorage::DISK)->exists($refusedPayment->slip_path))->toBeTrue();

    // ── 9. The merchant is told WHY, and every line is settleable again ───
    $this->actingAs($this->manager, 'merchant')
        ->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonPath('data.0.id', $firstId)
        ->assertJsonPath('data.0.merchant_status.code', 'rejected')
        ->assertJsonPath('data.0.merchant_status.rejection.reason', 'No transfer with reference BML-88421 reached the account on 5 August.')
        ->assertJsonPath('data.0.merchant_status.rejection.bank_ref', 'BML-88421');

    $this->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4)
        ->assertJsonPath('data.total.payable_laari', 11_825);

    // PLAN §1: "the merchant simply creates a new one" — a fresh reference,
    // the same four lines.
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.amount_due_laari', 11_663)
        ->assertJsonPath('data.payment_instructions.reference_preview', 'ST-2026-00002');

    // ── 10. The real transfer went out 45 laari short (§7 forgiveness) ────
    // 11,663 due − 45 = 11,618 transferred. The discount is covered funds,
    // so the gap the platform absorbs is still exactly 45 — a discount never
    // becomes forgiveness and forgiveness never eats a discount.
    $secondId = (int) $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => 11_618,
        'bank_ref' => 'BML-88999',
        'slip' => Slips::pdf(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.reference', 'ST-2026-00002')
        ->assertJsonPath('data.amount_due_laari', 11_663)
        ->assertJsonPath('data.discount_laari', 162)
        ->assertJsonCount(4, 'data.lines')
        ->json('data.id');

    expect($secondId)->not->toBe($firstId);

    // ── 11. The admin matches it: every reward confirms ───────────────────
    $this->actingAs($this->admin, 'admin');

    $paymentId = (int) $this->getJson("/api/admin/settlements/{$secondId}")
        ->assertOk()
        ->assertJsonPath('data.payments.0.slip_mime', 'application/pdf')
        ->json('data.payments.0.id');

    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_received_laari', 11_618)
        ->assertJsonPath('data.merchant_status.code', 'settled')
        ->assertJsonPath('data.lines.0.transaction.state', 'confirmed')
        ->assertJsonPath('data.lines.3.transaction.state', 'confirmed');

    foreach ($ids as $id) {
        $transaction = Transaction::query()->findOrFail($id);

        expect($transaction->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at)->not->toBeNull();
    }

    // Only now is the merchant's outstanding list empty — the match, not the
    // submission, is what funds the rewards.
    $this->actingAs($this->manager, 'merchant')
        ->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 0)
        ->assertJsonPath('data.total.payable_laari', 0);

    // ── 12. The ledger: cash books only what cash covered ─────────────────
    // §7/§8: the 45-laari gap is DR Platform-Funded Rewards / CR Merchant
    // Receivable — never bad debt, which is reserved for the 90-day default.
    // 11,618 cash + 162 discount + 45 forgiven = the whole 11,825 receivable.
    expect($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(11_618)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(45)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        // The customer liability is untouched by settlement — it was
        // recognised at accrual and survives until payout (§8) — and the
        // prompt-payment discount comes out of OUR revenue, not out of it.
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(8_600)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225 - 162)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    $this->artisan('manfaa:reconcile')->assertExitCode(0);

    $run = ReconciliationRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe('ok')
        ->and($run->issues)->toBeNull()
        ->and($run->totals['receivable']['derived_laari'])->toBe(0)
        ->and($run->totals['receivable']['ledger_laari'])->toBe(0)
        ->and($run->totals['liability']['derived_laari'])->toBe(8_600)
        ->and($run->totals['liability']['ledger_laari'])->toBe(8_600);

    // ── 13. And the customer, who was promised nothing until now ──────────
    $this->actingAs($this->customer, 'customer')
        ->withHeader('Referer', 'http://localhost')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 8_600)
        ->assertJsonPath('data.pending_laari', 0);
});

it('takes a 30-day-old credit straight to payable, settles it the same day, and refuses the vendor reversal 409', function () {
    // PLAN §1 "Backdated credits": no admin approval, immediately payable,
    // merchant-irreversible. One rule in CreditRecorder covers the manual
    // path and /v1 alike.
    $this->actingAs($this->superadmin, 'admin')
        ->postJson('/api/admin/platform/bank-accounts', [
            'bank_name' => 'Bank of Maldives',
            'account_no' => '7730000123456',
            'account_name' => 'Manfaa Pvt Ltd',
            'is_primary' => true,
            'active' => true,
        ])
        ->assertCreated();

    // ── 1. A sale from 30 days ago, keyed in at the counter today ─────────
    $id = (int) receiptLifecycleCredit('INV-BACKDATED-1', 100_000, $this->base->subDays(30))
        ->assertCreated()
        // Straight past awaiting_validation AND on_hold — no admin approval
        // stands between this sale and the settlement clock.
        ->assertJsonPath('data.state', 'payable_unfunded')
        ->assertJsonPath('data.reason_code', 'backdated_final')
        ->assertJsonPath('data.backdated', true)
        ->assertJsonPath('data.cashback_laari', 2_000)
        ->assertJsonPath('data.fee_laari', 750)
        ->json('data.id');

    $transaction = Transaction::query()->findOrFail($id);

    expect($transaction->events()->where('to_state', 'on_hold')->count())->toBe(0)
        ->and($transaction->events()->orderBy('id')->pluck('to_state')->all())
        ->toBe(['tracked', 'awaiting_validation', 'payable_unfunded'])
        // The 15-day clock starts NOW, not at the 30-day-old sale — an
        // instantly-overdue reward would suspend the merchant on day one.
        ->and($transaction->clock_start_at->equalTo($this->base))->toBeTrue()
        ->and($transaction->due_at->getTimestamp())->toBe(
            $this->base->setTimezone(config('app.business_timezone'))->addDays(15)->setTimezone('UTC')->getTimestamp(),
        );

    // ── 2. Immediately payable: no sweep, no wait, freshest age bucket ────
    $this->actingAs($this->manager, 'merchant')
        ->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 1)
        ->assertJsonPath('data.total.payable_laari', 2_750)
        ->assertJsonPath('data.buckets.0_5.count', 1)
        ->assertJsonPath('data.buckets.overdue.count', 0);

    // PLAN §1 prompt-payment discount, at the platform's shipped defaults
    // (500bp, 10 days): this batch covers everything outstanding and the line
    // is 0 days old, so 5% comes off the FEE — never the cashback.
    //   fee 750 → intdiv(750 * 500 + 9999, 10000) = intdiv(384999, 10000) = 38
    //   due 2,750 − 38 = 2,712   (cashback 2,000 untouched)
    expect(intdiv(750 * 500 + 9999, 10000))->toBe(38);

    $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 1)
        ->assertJsonPath('data.discount.eligible', true)
        ->assertJsonPath('data.discount.reason_code', 'eligible')
        ->assertJsonPath('data.discount.rate_bp', 500)
        ->assertJsonPath('data.discount_laari', 38)
        ->assertJsonPath('data.amount_due_before_discount_laari', 2_750)
        ->assertJsonPath('data.amount_due_laari', 2_712);

    // ── 3. The vendor tries to reverse it: 409, and nothing moves ─────────
    $this->vendorToken = $this->merchant->createToken('till', ['transactions:reverse'])->plainTextToken;

    receiptLifecycleVendorPost("/api/v1/transactions/{$id}/reverse", [
        'reason' => 'customer_refund',
        'occurred_at' => $this->base->subMinutes(5)->format('Y-m-d\TH:i:sP'),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', BackdatedIrreversibleException::ERROR_CODE)
        ->assertJsonPath('error.meta.state', 'payable_unfunded');

    // Not reversed, and — just as important — no §7 credit memo the merchant
    // could collect on the next batch instead. Correction is admin-only.
    expect(Transaction::query()->findOrFail($id)->state)->toBe(TransactionState::PayableUnfunded)
        ->and(Adjustment::query()->count())->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(2_750);

    // ── 4. And it settles through the ordinary receipt-first flow ─────────
    $settlementId = (int) $this->actingAs($this->manager, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 2_712,
            'bank_ref' => 'BML-BACKDATED-1',
            'slip' => Slips::png(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.amount_due_laari', 2_712)
        ->assertJsonPath('data.discount_laari', 38)
        ->assertJsonPath('data.discount_rate_bp', 500)
        ->assertJsonPath('data.discount_reason', 'eligible')
        ->json('data.id');

    $this->actingAs($this->admin, 'admin');

    $paymentId = (int) $this->getJson("/api/admin/settlements/{$settlementId}")
        ->assertOk()
        ->json('data.payments.0.id');

    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.lines.0.transaction.state', 'confirmed');

    expect(Transaction::query()->findOrFail($id)->state)->toBe(TransactionState::Confirmed)
        // The flag outlives the state: still irreversible once confirmed.
        ->and(Transaction::query()->findOrFail($id)->backdated)->toBeTrue()
        // 2,712 of cash + 38 of discount = the whole 2,750 receivable.
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(2_712)
        // The discount is a sales discount on OUR revenue: fee 750 → 712.
        // The customer's 2,000 liability is untouched (PLAN §1).
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(712)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2_000)
        // Nothing was forgiven: the discounted transfer covered the batch
        // exactly, with no residue and no sub-MVR-1 gap to absorb.
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    expect(SettlementAllocator::FORGIVENESS_THRESHOLD_LAARI)->toBe(100);
});
