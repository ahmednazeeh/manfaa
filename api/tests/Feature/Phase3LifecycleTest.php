<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;
use App\Domain\Standing\Reconciler;
use App\Models\AdminUser;
use App\Models\Claim;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\PayoutBatch;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('slips');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A vendor request through the real wire path (same discipline as the
 * Phase 2 lifecycle): resolved panel/customer guards dropped, headers
 * cleared, bearer token + Idempotency-Key only.
 */
function p3VendorPost(string $path, array $payload): TestResponse
{
    app('auth')->forgetGuards();
    test()->flushHeaders();

    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plaintextToken,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson($path, $payload);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function p3Sale(string $invoiceNo, int $eligible, string $occurredAt, array $overrides = []): array
{
    return [
        'invoice_no' => $invoiceNo,
        'customer_ref' => test()->customerCode,
        'eligible_amount' => $eligible,
        'occurred_at' => $occurredAt,
        ...$overrides,
    ];
}

/** A panel/admin request: bearer headers cleared, guard user re-applied. */
function p3ActingAs(MerchantUser|AdminUser $user): TestCase
{
    test()->flushHeaders();

    /** @var TestCase */
    return test()->actingAs($user, $user instanceof AdminUser ? 'admin' : 'merchant');
}

/** A customer-web request: headers cleared, customer guard applied. */
function p3AsCustomer(Customer $customer): TestCase
{
    test()->flushHeaders();

    /** @var TestCase */
    return test()->actingAs($customer, 'customer');
}

it('runs the full Phase 3 customer journey: OTP signup → promo publish → priced/clipped credits → balance → settlement → payout → claim → phone credit → discovery → reconcile', function () {
    Queue::fake();
    $this->seed(LedgerAccountSeeder::class);
    $balances = new Balances;

    // Outbound SMS captured through the §14 provider swap point — exactly
    // how a real sender replaces the default log driver.
    $sms = new class implements SmsSender
    {
        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $phone, string $message): void
        {
            $this->sent[] = ['phone' => $phone, 'message' => $message];
        }
    };
    $this->app->instance(SmsSender::class, $sms);

    // ── (a) Onboarded merchant on a standing 200bp rate (fee tier 75bp).
    $merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::parse('2026-01-01T00:00:00+05:00'),
        'effective_to' => null,
    ]);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $admin = AdminUser::factory()->create();

    // ── (b) §10 apps/web signup over real HTTP: request-otp → verify-otp →
    // register, the code lifted from the captured SMS (otp_codes stores only
    // the SHA-256 of it).
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-01T10:00:00+05:00'));
    $this->withHeader('Referer', 'http://localhost');

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])->assertOk();

    preg_match('/\b(\d{6})\b/', end($sms->sent)['message'], $matches);
    $code = $matches[1];

    expect(DB::table('otp_codes')->where('phone', '+9607712345')->value('code_hash'))
        ->not->toContain($code);

    $signupToken = $this->postJson('/api/customer/auth/verify-otp', [
        'phone' => '+9607712345',
        'code' => $code,
    ])->assertOk()->json('data.signup_token');

    $registered = $this->postJson('/api/customer/auth/register', [
        'signup_token' => $signupToken,
        'name' => 'Aishath Manike',
        'password' => 'correct-horse-battery',
    ])->assertCreated();

    $customer = Customer::query()->where('phone', '+9607712345')->sole();
    $this->customerCode = $registered->json('data.customer_code');

    expect($this->customerCode)->toMatch('/^\d{6}$/')
        ->and($customer->customer_code)->toBe($this->customerCode)
        ->and($customer->phone_verified_at)->not->toBeNull();

    // Registration logged the browser session in; close it so the vendor
    // wire calls below carry a bearer token and nothing else — Sanctum
    // consults the stateful session guards BEFORE the token, and /v1
    // insists on a real per-merchant credential.
    $this->postJson('/api/customer/auth/logout')->assertNoContent();

    // ── (c) The vendor credential, issued exactly as our team does it.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-05T09:00:00+05:00'));

    $vendorId = p3ActingAs($admin)
        ->postJson('/api/admin/pos-vendors', ['name' => 'TillWorks', 'contact' => 'integrations@tillworks.mv'])
        ->assertCreated()
        ->json('data.id');

    $this->plaintextToken = $this->postJson("/api/admin/merchants/{$merchant->id}/credentials", [
        'pos_vendor_id' => $vendorId,
        'abilities' => ['transactions:write'],
    ])->assertCreated()->json('plaintext_token');

    // ── (d) The merchant publishes a 500bp promotion (fee tier 100bp) for
    // Aug 10–12 with a MVR 1,000 minimum purchase and a 30,000-laari
    // per-customer cap. Published before the window opens — immutable after.
    $promotionId = p3ActingAs($owner)
        ->postJson('/api/merchant/promotions', [
            'cashback_rate_percent' => '5.00',
            'starts_at' => '2026-08-10T00:00:00+05:00',
            'ends_at' => '2026-08-12T00:00:00+05:00',
            'min_purchase_laari' => 100_000,
            'max_cashback_per_customer_laari' => 30_000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.platform_fee_percent', '1.00')
        ->assertJsonPath('data.all_in_percent', '6.00')
        ->json('data.id');

    $this->postJson("/api/merchant/promotions/{$promotionId}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // ── (e) In-window sale BELOW the promo's minimum purchase: the promo
    // never applies and never rejects — the standing 200/75 terms price it.
    // 50,000 @ 200bp/75bp → 1,000 + 375.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T09:00:00+05:00'));

    p3VendorPost('/api/v1/transactions', p3Sale('INV-3001', 50_000, '2026-08-10T08:30:00+05:00'))
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('transaction.origin', 'pos')
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75')
        ->assertJsonPath('transaction.cashback_laari', 1_000)
        ->assertJsonPath('transaction.fee_laari', 375);

    expect(Transaction::query()->where('invoice_no', 'INV-3001')->sole()->promotion_id)->toBeNull();

    // ── (f) In-window sale over the minimum: the promo prices it, the fee
    // follows the promo tier. 500,020 @ 500bp → 25,001; fee is a true §4
    // ceiling: 500,020 × 100bp = 5,000.2 → 5,001. Cap consumed: 25,001.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T10:30:00+05:00'));

    p3VendorPost('/api/v1/transactions', p3Sale('INV-3002', 500_020, '2026-08-10T10:00:00+05:00'))
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '5.00')
        ->assertJsonPath('transaction.platform_fee_percent', '1.00')
        ->assertJsonPath('transaction.cashback_laari', 25_001)
        ->assertJsonPath('transaction.fee_laari', 5_001);

    expect(Transaction::query()->where('invoice_no', 'INV-3002')->sole()->promotion_id)->toBe($promotionId);

    // ── (g) The cap clips: 200,000 @ 500bp wants 10,000 but only 4,999
    // laari of headroom remain. §4 caps: grant 4,999; the fee follows the
    // reward GRANTED — ceil(4,999 × 100/500) = ceil(999.8) = 1,000 — the
    // exact integers CashbackCalculator::calculateCapped produces.
    $clip = (new CashbackCalculator)->calculateCapped(Laari::of(200_000), Rate::cashback(500), Laari::of(4_999));

    expect($clip->cashbackLaari)->toBe(4_999)->and($clip->feeLaari)->toBe(1_000);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T11:30:00+05:00'));

    p3VendorPost('/api/v1/transactions', p3Sale('INV-3003', 200_000, '2026-08-10T11:00:00+05:00'))
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '5.00')
        ->assertJsonPath('transaction.platform_fee_percent', '1.00')
        ->assertJsonPath('transaction.cashback_laari', $clip->cashbackLaari)
        ->assertJsonPath('transaction.fee_laari', $clip->feeLaari);

    // The per-customer cap is now exactly exhausted — never exceeded.
    expect((int) Transaction::query()->where('promotion_id', $promotionId)->sum('cashback_laari'))->toBe(30_000);

    // ── (h) Discovery (§10 apps/web), unauthenticated, while the promo is
    // live: the merchant appears under "increased" at the boosted rate with
    // the "usually" standing rate alongside.
    $this->flushHeaders();
    app('auth')->forgetGuards();

    $discover = $this->getJson('/api/discover')->assertOk()->json('data');

    expect($discover['increased'])->toHaveCount(1)
        ->and($discover['increased'][0]['slug'])->toBe($merchant->slug)
        ->and($discover['increased'][0]['cashback_rate_percent'])->toBe('5.00')
        ->and($discover['increased'][0]['standing_cashback_rate_percent'])->toBe('2.00')
        ->and($discover['increased'][0]['promo_ends_at'])->not->toBeNull();

    // ── (i) Post-window sale: the promotion has lapsed purely temporally —
    // standing 200/75 again. 100,000 → 2,000 + 750.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-12T10:00:00+05:00'));

    p3VendorPost('/api/v1/transactions', p3Sale('INV-3004', 100_000, '2026-08-12T09:00:00+05:00'))
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75')
        ->assertJsonPath('transaction.cashback_laari', 2_000)
        ->assertJsonPath('transaction.fee_laari', 750);

    expect(Transaction::query()->where('invoice_no', 'INV-3004')->sole()->promotion_id)->toBeNull();

    // Four balanced accrual journals; every §4 line summed, never recomputed:
    // cashback 1,000+25,001+4,999+2,000 = 33,000; fee 375+5,001+1,000+750 = 7,126.
    expect(DB::table('ledger_journals')->count())->toBe(4)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(40_126)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(33_000)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(7_126);

    // ── (j) The customer's balance: pending ONLY — nothing is promised
    // before Confirmed, and the headline confirmed figure is zero.
    p3AsCustomer($customer)
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 0)
        ->assertJsonPath('data.pending_laari', 33_000)
        ->assertJsonPath('data.paid_this_month_laari', 0)
        ->assertJsonPath('data.has_payout_account', false);

    // ── (k) Day 0 of the clock for all four sales, then the receipt-first
    // settle-all (PLAN §1) — the batch covers everything outstanding on day
    // 0, so the prompt-payment discount takes 5% off the 7,126 of FEE
    // (intdiv(7126*500+9999, 10000) = 357) and the merchant transfers 39,769
    // and submits the slip in one call → admin matches: oldest-first
    // allocation confirms every line in full. The customers' 33,000 of
    // cashback is not reduced by a single laari.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-16T12:00:00+05:00'));
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    expect(Transaction::query()->where('state', 'payable_unfunded')->count())->toBe(4);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-16T13:00:00+05:00'));

    p3ActingAs($owner)
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 39_769,
            'bank_ref' => 'BML-P3-39769',
            'slip' => Slips::webp(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.cashback_total_laari', 33_000)
        ->assertJsonPath('data.fee_total_laari', 7_126)
        ->assertJsonPath('data.discount_laari', 357)
        ->assertJsonPath('data.amount_due_laari', 39_769);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-17T10:00:00+05:00'));

    p3ActingAs($admin);

    $paymentId = (int) DB::table('settlement_payments')->where('bank_ref', 'BML-P3-39769')->value('id');
    $this->postJson("/api/admin/payments/{$paymentId}/match")->assertOk();

    expect(Transaction::query()->where('state', 'confirmed')->count())->toBe(4)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($balances->accountBalance(AccountCode::SettlementCash))->toBe(39_769)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // The headline moves: confirmed 33,000, pending drops to zero.
    p3AsCustomer($customer)
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 33_000)
        ->assertJsonPath('data.pending_laari', 0);

    // ── (l) Payout account registered through the customer web.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-18T10:00:00+05:00'));

    $this->postJson('/api/customer/payout-account', [
        'bank_name' => 'Bank of Maldives',
        'account_no' => '7712345678901',
        'account_name' => 'AISHATH MANIKE',
    ])->assertOk()->assertJsonPath('data.has_payout_account', true);

    // ── (m) Past the §13 cutoff (the 24th 23:59), the August batch builds:
    // one customer, 33,000 laari. Dual approval by two DISTINCT admins, bank
    // file export, result import — and the reward is Paid.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-26T09:00:00+05:00'));

    $batchId = p3ActingAs($admin)
        ->postJson('/api/admin/payout-batches', ['year' => 2026, 'month' => 8])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PB-2026-08')
        ->assertJsonPath('data.customer_count', 1)
        ->assertJsonPath('data.total_laari', 33_000)
        ->json('data.id');

    $this->postJson("/api/admin/payout-batches/{$batchId}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.approved_by_first', $admin->id);

    p3ActingAs(AdminUser::factory()->create())
        ->postJson("/api/admin/payout-batches/{$batchId}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved');

    $export = $this->post("/api/admin/payout-batches/{$batchId}/export");
    $export->assertOk();

    $item = PayoutBatch::query()->findOrFail($batchId)->items()->sole();

    expect(trim($export->getContent()))->toBe(implode("\n", [
        'item_id,account_no,account_name,bank_name,amount_mvr',
        "{$item->id},7712345678901,AISHATH MANIKE,Bank of Maldives,330.00",
    ]));

    $this->post("/api/admin/payout-batches/{$batchId}/import", [
        'file' => UploadedFile::fake()->createWithContent(
            'results.csv',
            "item_id,status,reference,failure_reason\n{$item->id},paid,BML-R-3300,\n",
        ),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed');

    // One payout journal for the item's stored sum; the liability released
    // by exactly the integers paid, and the transactions are Paid.
    expect(DB::table('ledger_journals')->where('reference_type', 'payout_item')->count())->toBe(1)
        ->and(Transaction::query()->where('state', 'paid')->count())->toBe(4)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(0)
        ->and($balances->accountBalance(AccountCode::SettlementCash))->toBe(39_769 - 33_000)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // paid_this_month reads the event log in business time: 33,000 in August.
    p3AsCustomer($customer)
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 0)
        ->assertJsonPath('data.pending_laari', 0)
        ->assertJsonPath('data.paid_this_month_laari', 33_000);

    // ── (n) A missed sale claimed and approved: the admin approval mints an
    // origin-'claim' transaction at the rate effective on the PURCHASE date
    // (standing 200/75 — the promo had lapsed by Aug 20), with a normal §4
    // accrual: 33,333 → 667 + 250, immediately on the settlement clock.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-27T09:00:00+05:00'));

    $claimId = p3AsCustomer($customer)
        ->postJson('/api/customer/claims', [
            'merchant_slug' => $merchant->slug,
            'purchased_at' => '2026-08-20',
            'amount_laari' => 33_333,
            'receipt_no' => 'RCPT-9001',
            'note' => 'Till was offline; cashier kept the receipt copy.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'open')
        ->json('data.id');

    p3ActingAs($admin)
        ->postJson("/api/admin/claims/{$claimId}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved');

    $claimTx = Transaction::query()->findOrFail(
        Claim::query()->findOrFail($claimId)->resulting_transaction_id,
    );

    expect($claimTx->origin)->toBe('claim')
        ->and($claimTx->customer_id)->toBe($customer->id)
        ->and($claimTx->invoice_no)->toBe('RCPT-9001')
        ->and($claimTx->rate_bp)->toBe(200)
        ->and($claimTx->cashback_laari)->toBe(667)
        ->and($claimTx->fee_laari)->toBe(250)
        ->and($claimTx->state->value)->toBe('payable_unfunded')
        ->and(DB::table('ledger_journals')->where('reference_type', 'transaction')->where('reference_id', $claimTx->id)->count())->toBe(1);

    // ── (o) Original-spec online model 3: a phone-keyed v1 credit records
    // origin api_phone and lands in the SAME customer's pending.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-27T10:00:00+05:00'));

    p3VendorPost('/api/v1/transactions', p3Sale('INV-3005', 60_000, '2026-08-27T09:30:00+05:00', [
        'customer_ref' => '+9607712345',
    ]))
        ->assertCreated()
        ->assertJsonPath('transaction.origin', 'api_phone')
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.cashback_laari', 1_200)
        ->assertJsonPath('transaction.fee_laari', 450);

    expect(Transaction::query()->where('invoice_no', 'INV-3005')->sole()->customer_id)->toBe($customer->id);

    // Both post-payout rewards sit in pending, conditional; the paid figure
    // stands; history shows the phone credit newest, as Pending.
    p3AsCustomer($customer)
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 0)
        ->assertJsonPath('data.pending_laari', 667 + 1_200)
        ->assertJsonPath('data.paid_this_month_laari', 33_000);

    $this->getJson('/api/customer/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.cashback_laari', 1_200)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('meta.total', 6);

    // ── (p) Reconciliation, end to end: every journal balances and the
    // totals DERIVED from the transactions alone equal the ledger, to the
    // laari.
    //   receivable: claim 917 + phone credit 1,650 .................. 2,567
    //   liability:  667 + 1,200 pending (33,000 paid out) ........... 1,867
    //   revenue:    7,126 + 250 + 450 − 357 prompt-payment discount .. 7,469
    $run = app(Reconciler::class)->run();

    expect($run->status)->toBe('ok')
        ->and($run->issues)->toBeNull()
        ->and($run->journals_checked)->toBe(9)
        ->and($run->totals['receivable'])->toBe(['derived_laari' => 2_567, 'ledger_laari' => 2_567])
        ->and($run->totals['liability'])->toBe(['derived_laari' => 1_867, 'ledger_laari' => 1_867])
        ->and($run->totals['revenue'])->toBe(['derived_laari' => 7_469, 'ledger_laari' => 7_469]);

    $trial = collect($balances->trialBalance())->map(fn (array $row) => $row['balance_laari'])->all();

    expect($trial)->toBe([
        1000 => 6_769,  // Settlement Cash: 39,769 received − 33,000 paid out
        1100 => 2_567,  // Merchant Receivable: claim + phone credit on the clock
        2100 => -1_867, // Customer Cashback Liability: the two pending rewards
        2200 => 0,      // Merchant Wallet Balance
        2300 => 0,      // Fee GST Payable
        4100 => -7_469, // Platform Fee Revenue, net of the 357 discounted
        5100 => 0,      // Platform-Funded Rewards
        5900 => 0,      // Bad Debt Expense
    ])
        ->and(array_sum($trial))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // No silent state mutation: 5 events on each settled-and-paid sale
    // (created/awaiting/payable/confirmed/paid), 3 on the claim credit,
    // 2 on the phone credit = 25.
    expect(TransactionEvent::query()->count())->toBe(25);
});
