<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Standing\Reconciler;
use App\Jobs\SendWebhook;
use App\Models\Adjustment;
use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * A vendor request through the real wire path: bearer token, JSON body,
 * optional Idempotency-Key. actingAs() leaves users sitting on the session
 * guards and config('sanctum.guard') consults those BEFORE the bearer token,
 * so the resolved guards are dropped first — a till request arrives with no
 * panel session, and the test must look exactly like one.
 */
function p2VendorPost(string $path, array $payload, ?string $key = null): TestResponse
{
    app('auth')->forgetGuards();
    test()->flushHeaders();

    $headers = ['Authorization' => 'Bearer '.test()->plaintextToken];

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return test()->withHeaders($headers)->postJson($path, $payload);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function p2Sale(string $invoiceNo, int $eligible, string $occurredAt, array $overrides = []): array
{
    return [
        'invoice_no' => $invoiceNo,
        'customer_ref' => test()->customer->customer_code,
        'eligible_amount' => $eligible,
        'occurred_at' => $occurredAt,
        ...$overrides,
    ];
}

/** A panel/admin request: bearer headers cleared, guard user re-applied. */
function p2ActingAs(MerchantUser|AdminUser $user): TestCase
{
    test()->flushHeaders();

    /** @var TestCase */
    return test()->actingAs($user, $user instanceof AdminUser ? 'admin' : 'merchant');
}

/**
 * Recursively key-sorts a decoded JSON array so replayed bodies (stored in
 * jsonb, which does not preserve object key order) compare value-identical.
 *
 * @param  array<array-key, mixed>  $json
 * @return array<array-key, mixed>
 */
function p2Canonical(array $json): array
{
    ksort($json);

    return array_map(fn ($value) => is_array($value) ? p2Canonical($value) : $value, $json);
}

/** Journal count for one reference with the given description. */
function p2Journals(string $referenceType, int $referenceId, string $description): int
{
    return DB::table('ledger_journals')
        ->where('reference_type', $referenceType)
        ->where('reference_id', $referenceId)
        ->where('description', $description)
        ->count();
}

/**
 * Merchant-side receipt-first settle-all (PLAN §1): one call builds the
 * batch, freezes its lines and attaches the transfer's slip — the batch
 * exists in payment_review or not at all.
 */
function p2SettleAll(MerchantUser $owner, int $amountLaari, string $bankRef): Settlement
{
    $response = p2ActingAs($owner)
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

/** Admin-side: match the merchant's claimed transfer. */
function p2PayAndMatch(AdminUser $admin, Settlement $settlement, string $bankRef): Settlement
{
    $paymentId = $settlement->payments()->where('bank_ref', $bankRef)->sole()->id;

    p2ActingAs($admin)->postJson("/api/admin/payments/{$paymentId}/match")->assertOk();

    return $settlement->refresh();
}

it('runs the full Phase 2 vendor lifecycle: credential → POS ingest → replay → rate boundary → reversal → locked-line adjustment → netted batch → suspension → revocation → reconcile', function () {
    Queue::fake();
    $this->seed(LedgerAccountSeeder::class);
    $balances = new Balances;

    // ── (a) Onboarding, exactly as our team does it: merchant on a standing
    // 200bp rate, POS vendor registered, one credential issued over the admin
    // API — the plaintext token appears exactly once, in this 201 body.
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
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    Carbon::setTestNow(CarbonImmutable::parse('2026-07-30T10:00:00+05:00'));

    $vendorId = p2ActingAs($admin)
        ->postJson('/api/admin/pos-vendors', ['name' => 'TillWorks', 'contact' => 'integrations@tillworks.mv'])
        ->assertCreated()
        ->json('data.id');

    $issued = $this->postJson("/api/admin/merchants/{$merchant->id}/credentials", [
        'pos_vendor_id' => $vendorId,
        'abilities' => ['transactions:write', 'transactions:reverse', 'rates:read'],
    ])->assertCreated();

    $this->plaintextToken = $issued->json('plaintext_token');
    $credentialId = $issued->json('credential.id');
    $credential = ApiCredential::query()->findOrFail($credentialId);

    // Only the SHA-256 digest of the secret half is ever stored.
    expect($this->plaintextToken)->toContain('|')
        ->and($credential->token_hash)->toBe(hash('sha256', explode('|', $this->plaintextToken, 2)[1]))
        ->and($credential->revoked_at)->toBeNull()
        ->and($credential->abilities)->toBe(['transactions:write', 'transactions:reverse', 'rates:read']);

    // §9.3: the vendor subscribes one endpoint to the full event catalogue.
    $this->postJson("/api/admin/pos-vendors/{$vendorId}/webhook-endpoints", [
        'url' => 'https://tillworks.example/hooks/manfaa',
        'events' => ['merchant.rate_changed', 'merchant.suspended', 'merchant.reinstated', 'transaction.reversed'],
    ])->assertCreated();

    // ── (b) First sale over the wire. 118000 @ 200bp/75bp: both components
    // are true ceilings of the §4 rule, and exactly one balanced accrual
    // journal exists.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-01T10:00:00+05:00'));

    expect(intdiv(118_000 * 200 + 9999, 10_000))->toBe(2_360)
        ->and(intdiv(118_000 * 75 + 9999, 10_000))->toBe(885);

    $key1 = (string) Str::uuid();
    $sale1 = p2Sale('INV-2001', 118_000, '2026-08-01T09:00:00+05:00', ['sale_amount' => 125_000]);

    $first = p2VendorPost('/api/v1/transactions', $sale1, $key1)
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('transaction.origin', 'pos')
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75')
        ->assertJsonPath('transaction.cashback_laari', 2_360)
        ->assertJsonPath('transaction.fee_laari', 885);

    $tx1 = Transaction::query()->sole();

    expect(DB::table('ledger_journals')->count())->toBe(1)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(3_245)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2_360)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(885);

    // ── (c) The till retries the same POST with the same key: byte-identical
    // replay, no second row, no second journal.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-01T10:05:00+05:00'));

    $replay = p2VendorPost('/api/v1/transactions', $sale1, $key1)
        ->assertOk()
        ->assertHeader('Idempotency-Replay', 'true');

    expect(p2Canonical($replay->json()))->toBe(p2Canonical($first->json()))
        ->and(Transaction::query()->count())->toBe(1)
        ->and(DB::table('ledger_journals')->count())->toBe(1);

    // ── (d) Second sale: 50000 @ 200bp/75bp → 1000 + 375, due 1375.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-01T11:00:00+05:00'));

    p2VendorPost('/api/v1/transactions', p2Sale('INV-2002', 50_000, '2026-08-01T10:30:00+05:00'), (string) Str::uuid())
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_laari', 1_000)
        ->assertJsonPath('transaction.fee_laari', 375);

    $tx2 = Transaction::query()->where('invoice_no', 'INV-2002')->sole();

    // ── (e) §7 rate decrease: scheduled at 22:00, it lands at 00:00 next
    // business day — a stale till cache can only under-promise, never over.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-01T22:00:00+05:00'));

    p2ActingAs($owner)
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '1.00'])
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.pending.cashback_rate_percent', '1.00')
        ->assertJsonPath('data.pending.platform_fee_percent', '0.50')
        ->assertJsonPath('change.applies', 'next_business_midnight')
        ->assertJsonPath('change.effective_at', '2026-08-02T00:00:00+05:00');

    // Two sales straddling the boundary, POSTed together after midnight:
    // 23:59 still earns the advertised 200bp; 00:01 earns the reduced 100bp
    // (fee tier drops to 50bp with it). Both fees are true ceilings:
    // 61500·75bp = 461.25 → 462, and 61500·50bp = 307.5 → 308.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-02T00:30:00+05:00'));

    p2VendorPost('/api/v1/transactions', p2Sale('INV-2003', 61_500, '2026-08-01T23:59:00+05:00'), (string) Str::uuid())
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75')
        ->assertJsonPath('transaction.cashback_laari', 1_230)
        ->assertJsonPath('transaction.fee_laari', 462);

    p2VendorPost('/api/v1/transactions', p2Sale('INV-2004', 61_500, '2026-08-02T00:01:00+05:00'), (string) Str::uuid())
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '1.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.50')
        ->assertJsonPath('transaction.cashback_laari', 615)
        ->assertJsonPath('transaction.fee_laari', 308);

    $tx3 = Transaction::query()->where('invoice_no', 'INV-2003')->sole();
    $tx4 = Transaction::query()->where('invoice_no', 'INV-2004')->sole();

    // ── (f) The first sale is refunded before any settlement exists: the
    // vendor's contractual reversal POST reverses in place, the accrual is
    // mirrored from the STORED integers, and transaction.reversed is queued.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-02T01:00:00+05:00'));

    p2VendorPost("/api/v1/transactions/{$tx1->id}/reverse", [
        'reason' => 'customer_refund',
        'occurred_at' => '2026-08-02T00:55:00+05:00',
    ], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('outcome', 'reversed')
        ->assertJsonPath('adjustment', null)
        ->assertJsonPath('transaction.state', 'reversed');

    expect($tx1->refresh()->state)->toBe(TransactionState::Reversed)
        ->and(p2Journals('transaction', $tx1->id, 'Cashback accrual reversed'))->toBe(1)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(1_375 + 1_692 + 923)
        ->and($balances->journalsAllBalance())->toBeTrue();

    $reversalDelivery = WebhookDelivery::query()->where('event', 'transaction.reversed')->sole();

    expect($reversalDelivery->payload['data']['transaction_id'])->toBe($tx1->id)
        ->and($reversalDelivery->payload['data']['invoice_no'])->toBe('INV-2001');
    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $reversalDelivery->id);

    // ── (g) Day 0 of the clock: every validation window has elapsed by
    // Aug 5 noon; the three live sales move onto the 15-day clock.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-05T12:00:00+05:00'));
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    $dueAt = CarbonImmutable::parse('2026-08-20T12:00:00+05:00');

    expect(Transaction::query()->where('state', TransactionState::PayableUnfunded)->count())->toBe(3)
        ->and($tx2->refresh()->due_at->equalTo($dueAt))->toBeTrue();

    // ── (h) Settle-all → submit: the batch snapshots the three payable lines
    // (2845 cashback + 1145 fee = 3990 of lines) and freezes them. It covers
    // everything outstanding on day 0, so PLAN §1 takes 5% off the FEE —
    // intdiv(1145*500+9999, 10000) = 58 — and 3,932 is what the vendor's
    // merchant transfers.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-05T13:00:00+05:00'));

    $settlement1 = p2SettleAll($owner, 3_932, 'BML-P2-3932');

    expect($settlement1->cashback_total_laari)->toBe(2_845)
        ->and($settlement1->fee_total_laari)->toBe(1_145)
        ->and($settlement1->discount_laari)->toBe(58)
        ->and($settlement1->amount_due_laari)->toBe(3_932)
        ->and($settlement1->lines()->count())->toBe(3)
        ->and($settlement1->due_at->equalTo($dueAt))->toBeTrue();

    // ── (i) A refund arrives for INV-2002 while its line is locked in the
    // submitted batch. §7: the transaction cannot reverse; a pending credit
    // adjustment is created (memo only — no journal until application), and
    // the distinct outcome tells the vendor apart from a failure.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-05T14:00:00+05:00'));

    p2VendorPost("/api/v1/transactions/{$tx2->id}/reverse", [
        'reason' => 'customer_refund',
        'occurred_at' => '2026-08-05T13:45:00+05:00',
    ], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('outcome', 'adjustment_created')
        ->assertJsonPath('cause', 'locked_in_settlement')
        ->assertJsonPath('adjustment.amount_laari', -1_375)
        ->assertJsonPath('transaction.state', 'payable_unfunded');

    $adjustment = Adjustment::query()->sole();

    expect($adjustment->state)->toBe('pending')
        ->and($adjustment->cashback_laari)->toBe(-1_000)
        ->and($adjustment->fee_laari)->toBe(-375)
        ->and($adjustment->settlement_id)->toBeNull()
        ->and(p2Journals('transaction', $tx2->id, 'Cashback accrual reversed'))->toBe(0)
        ->and(p2Journals('adjustment', $adjustment->id, 'Cashback accrual reversed'))->toBe(0)
        // No echo webhook: the vendor saw adjustment_created synchronously.
        ->and(WebhookDelivery::query()->where('event', 'transaction.reversed')->count())->toBe(1);

    // ── (j) The merchant pays the full discounted 3,932 — the credit nets
    // the NEXT batch, never the locked one. All three lines confirm,
    // oldest-first, with the 58 discount covering the rest.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-06T10:00:00+05:00'));

    $settlement1 = p2PayAndMatch($admin, $settlement1, 'BML-P2-3932');

    expect($settlement1->state->value)->toBe('settled')
        ->and($settlement1->amount_received_laari)->toBe(3_932)
        ->and($settlement1->discount_posted_laari)->toBe(58);

    foreach ([$tx2, $tx3, $tx4] as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed);
    }

    expect(p2Journals('settlement', $settlement1->id, 'Bank settlement received'))->toBe(1)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // ── (k) A fresh sale at the reduced rate: 100000 @ 100bp/50bp → 1000 +
    // 500, due 1500 — the next batch's only line.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-06T15:00:00+05:00'));

    p2VendorPost('/api/v1/transactions', p2Sale('INV-2005', 100_000, '2026-08-06T14:00:00+05:00'), (string) Str::uuid())
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_laari', 1_000)
        ->assertJsonPath('transaction.fee_laari', 500);

    $tx5 = Transaction::query()->where('invoice_no', 'INV-2005')->sole();

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T12:00:00+05:00'));
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    // ── (l) The next draft nets the adjustment: amount_due = 1500 − 1375,
    // the adjustment flips to applied with the settlement linkage, and its
    // credit journal posts NOW — application time, from the stored negated
    // integers — DR platform-funded 1000 / DR revenue 375 / CR receivable
    // 1375. The cashback share charges Platform-Funded Rewards, NOT the
    // customer liability: INV-2002's reward is confirmed and will still be
    // paid out, so the obligation to the customer survives the merchant's
    // credit.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T13:00:00+05:00'));

    $settlement2 = p2SettleAll($owner, 100, 'BML-P2-100');
    $adjustment->refresh();

    // 1,500 of lines − 1,375 of credit − a 25 discount on its 500 of fee.
    expect($settlement2->cashback_total_laari)->toBe(1_000)
        ->and($settlement2->fee_total_laari)->toBe(500)
        ->and($settlement2->discount_laari)->toBe(25)
        ->and($settlement2->amount_due_laari)->toBe(100)
        ->and($settlement2->lines()->count())->toBe(1)
        ->and($adjustment->state)->toBe('applied')
        ->and($adjustment->settlement_id)->toBe($settlement2->id)
        ->and($adjustment->applied_at)->not->toBeNull()
        ->and(p2Journals('adjustment', $adjustment->id, 'Adjustment credit applied'))->toBe(1)
        ->and(p2Journals('adjustment', $adjustment->id, 'Cashback accrual reversed'))->toBe(0)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(125)
        ->and($balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(1_000)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // Mid-flight reconciliation: with the credit applied but not yet consumed
    // by an allocation, the transaction-derived totals still equal the ledger.
    $midRun = app(Reconciler::class)->run();

    expect($midRun->status)->toBe('ok')
        ->and($midRun->issues)->toBeNull()
        // 8 before the incentive, plus settlement1's discount journal.
        ->and($midRun->journals_checked)->toBe(9)
        ->and($midRun->totals['receivable'])->toBe(['derived_laari' => 125, 'ledger_laari' => 125])
        // Liability = every live reward in full (1000+1230+615 confirmed +
        // 1000 payable): the applied adjustment released none of it.
        ->and($midRun->totals['liability'])->toBe(['derived_laari' => 3_845, 'ledger_laari' => 3_845])
        // Fee revenue net of the 58 already discounted away at (j).
        ->and($midRun->totals['revenue'])->toBe(['derived_laari' => 1_270 - 58, 'ledger_laari' => 1_270 - 58]);

    // ── (m) The merchant pays the NETTED 100 and the batch settles in full:
    // the applied credit already moved the ledger at application, so cash
    // books only 100, the 25 discount posts as the batch allocates, nothing
    // is "forgiven", no wallet row appears, and the receivable returns to
    // zero.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T14:00:00+05:00'));
    $settlement2 = p2PayAndMatch($admin, $settlement2, 'BML-P2-100');

    expect($settlement2->state->value)->toBe('settled')
        ->and($settlement2->amount_received_laari)->toBe(100)
        ->and($settlement2->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($tx5->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and(p2Journals('settlement', $settlement2->id, 'Settlement shortfall forgiven'))->toBe(0)
        ->and(p2Journals('settlement', $settlement2->id, 'Bank settlement received'))->toBe(1)
        ->and(DB::table('wallet_transactions')->count())->toBe(0)
        ->and($balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(0)
        // The 1000 platform-funded expense from (l) is the standing cost of
        // the confirmed-but-refunded INV-2002 reward — untouched by payment.
        ->and($balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(1_000)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // ── (n) One more sale (30000 @ 100bp → 300 + 150) goes payable on
    // Aug 14 and is left unpaid: day 16 suspends the merchant automatically,
    // with the notice recorded and merchant.suspended queued to the vendor.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-11T10:00:00+05:00'));

    p2VendorPost('/api/v1/transactions', p2Sale('INV-2006', 30_000, '2026-08-11T09:30:00+05:00'), (string) Str::uuid())
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_laari', 300)
        ->assertJsonPath('transaction.fee_laari', 150);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-14T12:00:00+05:00'));
    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-30T09:00:00+05:00'));
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'suspended')->count())->toBe(1);

    $suspendedDelivery = WebhookDelivery::query()->where('event', 'merchant.suspended')->sole();

    expect($suspendedDelivery->payload['data']['merchant_id'])->toBe($merchant->id)
        ->and($suspendedDelivery->payload['data']['reason'])->toBe('overdue_settlement');

    // §7: suspension stops cashback CREATION, not ingestion. The till keeps
    // POSTing; the sale records with zero laari, the frozen 100bp terms as
    // evidence, a terminal state, no journal — and the cashier sees a
    // truthful 200, never an error.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-30T10:00:00+05:00'));

    p2VendorPost('/api/v1/transactions', p2Sale('INV-2007', 80_000, '2026-08-30T09:00:00+05:00'), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('status', 'recorded_ineligible')
        ->assertJsonPath('reason', 'merchant_suspended')
        ->assertJsonPath('transaction.state', 'reversed')
        ->assertJsonPath('transaction.cashback_laari', 0)
        ->assertJsonPath('transaction.fee_laari', 0)
        ->assertJsonPath('transaction.cashback_rate_percent', '1.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.50');

    expect(Transaction::query()->count())->toBe(7)
        // 10 before the incentive, plus one prompt-payment discount journal
        // on each of the two settled batches.
        ->and(DB::table('ledger_journals')->count())->toBe(12);

    // The manual admin path back, with its mandatory note — recorded in the
    // append-only notice trail and echoed to the vendor as merchant.reinstated.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-30T11:00:00+05:00'));

    p2ActingAs($admin)
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", [
            'note' => 'Settlement plan agreed with owner; INV-2006 to be paid Monday.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($merchant->refresh()->status)->toBe('active')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->sole()->payload['manual'])->toBeTrue()
        ->and(WebhookDelivery::query()->where('event', 'merchant.reinstated')->count())->toBe(1);

    // ── (o) Credential revoked: auth dies immediately, the audit row stays.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-30T12:00:00+05:00'));

    $this->deleteJson("/api/admin/credentials/{$credentialId}")->assertOk();

    expect($credential->refresh()->revoked_at)->not->toBeNull()
        ->and($credential->revoked_by)->toBe($admin->id);

    p2VendorPost('/api/v1/transactions', p2Sale('INV-2008', 40_000, '2026-08-30T11:30:00+05:00'), (string) Str::uuid())
        ->assertUnauthorized();

    expect(Transaction::query()->count())->toBe(7);

    // ── (p) Final reconciliation, the §5 invariant reused end-to-end: every
    // journal balances and the totals derived from the transactions (and
    // applied adjustments) alone equal the ledger, to the laari.
    //   receivable: INV-2006 still on the clock ....................... 450
    //   liability:  1000+1230+615+1000+300 confirmed/payable — in FULL:
    //               the applied adjustment funded INV-2002's reward from
    //               the platform, it did not release the customer's claim . 4145
    //   revenue:    375+462+308+500+150 − 375 adj − 58 − 25 discount .. 1337
    //   platform-funded: the adjusted reward's cashback share ......... 1000
    //   cash:       3932 + 100 ........................................ 4032
    $finalRun = app(Reconciler::class)->run();

    expect($finalRun->status)->toBe('ok')
        ->and($finalRun->issues)->toBeNull()
        ->and($finalRun->journals_checked)->toBe(12)
        ->and($finalRun->totals['receivable'])->toBe(['derived_laari' => 450, 'ledger_laari' => 450])
        ->and($finalRun->totals['liability'])->toBe(['derived_laari' => 4_145, 'ledger_laari' => 4_145])
        ->and($finalRun->totals['revenue'])->toBe(['derived_laari' => 1_337, 'ledger_laari' => 1_337]);

    $trial = collect($balances->trialBalance())->map(fn (array $row) => $row['balance_laari'])->all();

    expect($trial)->toBe([
        1000 => 4_032,  // Settlement Cash (3,932 + 100 transferred)
        1100 => 450,    // Merchant Receivable
        2100 => -4_145, // Customer Cashback Liability
        2200 => 0,      // Merchant Wallet Balance
        2300 => 0,      // Fee GST Payable
        4100 => -1_337, // Platform Fee Revenue, net of the 83 discounted
        5100 => 1_000,  // Platform-Funded Rewards
        5900 => 0,      // Bad Debt Expense
    ])
        ->and(array_sum($trial))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue();

    // No silent state mutation: every hop on all seven transactions is
    // evidenced by exactly one event row. tx1 3 (created/awaiting/reversed),
    // tx2–tx5 4 each (…/payable/confirmed), tx6 3 (…/payable), tx7 2
    // (created/reversed) = 24.
    expect(TransactionEvent::query()->count())->toBe(24);

    // Exactly four vendor-facing events left the building, one queued job each.
    expect(WebhookDelivery::query()->pluck('event')->sort()->values()->all())->toBe([
        'merchant.rate_changed',
        'merchant.reinstated',
        'merchant.suspended',
        'transaction.reversed',
    ]);
    Queue::assertPushed(SendWebhook::class, 4);
});
