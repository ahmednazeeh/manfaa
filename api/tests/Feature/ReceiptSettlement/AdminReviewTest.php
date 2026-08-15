<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Standing\Reconciler;
use App\Models\Adjustment;
use App\Models\AdminUser;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    $this->fixture = SettlementFixture::payableBatch();
    $this->admin = AdminUser::factory()->create();
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The receipt-first submission: settle-all with a slip, landing in
 * payment_review. Returns the settlement id.
 */
function submitReceipt(int $amountLaari, string $bankRef): int
{
    return test()->actingAs(test()->fixture->user, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => $amountLaari,
            'bank_ref' => $bankRef,
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->json('data.id');
}

it('matches a reviewed receipt and confirms every line, forgiving the sub-MVR-1 gap', function () {
    // 11,780 against 11,825: 45 laari short, under MVR 1 → §7 forgiveness.
    $settlementId = submitReceipt(11_780, 'BML-MATCH-1');
    $paymentId = SettlementPayment::query()->sole()->id;

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_received_laari', 11_780);

    foreach ($this->fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at)->not->toBeNull();
    }

    // Allocation semantics are untouched by the new entry point: cash books
    // what cash covered, the platform absorbs the 45, bad debt stays clean.
    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(11_780)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(45)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    expect(app(Reconciler::class)->run()->status)->toBe('ok');
});

it('parks an overpayment in the wallet, exactly as the admin-recorded path does', function () {
    $settlementId = submitReceipt(12_000, 'BML-OVER-1');
    $paymentId = SettlementPayment::query()->sole()->id;

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled');

    expect($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(175)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(175)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    expect(Settlement::query()->findOrFail($settlementId)->amount_received_laari)->toBe(12_000);
});

it('allocates oldest-first and leaves the uncovered lines pending on a partial receipt', function () {
    // 2750 + 1375 = 4125 covers the two oldest lines only.
    $settlementId = submitReceipt(4_125, 'BML-PARTIAL-1');
    $paymentId = SettlementPayment::query()->sole()->id;

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'partially_settled');

    expect($this->fixture->transactions[0]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($this->fixture->transactions[1]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($this->fixture->transactions[2]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($this->fixture->transactions[3]->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // The remainder is paid with a SECOND receipt on the same batch — §7
    // leaves the uncovered lines frozen here, so a new batch cannot take
    // them and the merchant must be able to top this one up.
    $this->actingAs($this->fixture->user, 'merchant')
        ->post("/api/merchant/settlements/{$settlementId}/receipts", [
            'amount' => 7_700,
            'bank_ref' => 'BML-PARTIAL-2',
            'slip' => Slips::pdf(),
        ])
        ->assertCreated();

    $second = SettlementPayment::query()->where('bank_ref', 'BML-PARTIAL-2')->sole();

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/payments/{$second->id}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled');

    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('releases every line on reject, shows the merchant the reason, and lets them settle again', function () {
    $settlementId = submitReceipt(11_825, 'BML-REJECT-1');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/settlements/{$settlementId}/reject", [
            'reason' => 'No transfer with this reference reached the account.',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    $settlement = Settlement::query()->findOrFail($settlementId);
    $payment = SettlementPayment::query()->sole();

    expect($settlement->state)->toBe(SettlementState::Cancelled)
        ->and($settlement->lines()->count())->toBe(0)
        ->and($payment->state)->toBe('rejected')
        ->and($payment->rejected_by)->toBe($this->admin->id)
        ->and($payment->rejected_at)->not->toBeNull()
        ->and($payment->rejection_reason)->toBe('No transfer with this reference reached the account.');

    // Nothing was ever allocated, so no customer's cashback moved and the
    // ledger never saw the claimed cash.
    foreach ($this->fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded);
    }

    expect($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11_825)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // The merchant sees WHY, on their own listing.
    $this->actingAs($this->fixture->user, 'merchant')
        ->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonPath('data.0.merchant_status.code', 'rejected')
        ->assertJsonPath('data.0.merchant_status.rejection.reason', 'No transfer with this reference reached the account.')
        ->assertJsonPath('data.0.merchant_status.rejection.bank_ref', 'BML-REJECT-1');

    // And the transactions are immediately settleable again — the whole
    // point of the release (PLAN §1: "the merchant simply creates a new one").
    $this->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4)
        ->assertJsonPath('data.total.payable_laari', 11_825);

    $newId = submitReceipt(11_825, 'BML-REJECT-1-RETRY');

    expect($newId)->not->toBe($settlementId)
        ->and(Settlement::query()->findOrFail($newId)->lines()->count())->toBe(4);
});

it('un-applies the §7 credits a rejected batch had netted', function () {
    // Confirm + refund the oldest line so a 2,750 credit memo exists.
    $builder = app(SettlementBuilder::class);
    $allocator = app(SettlementAllocator::class);

    $t0 = $this->fixture->transactions[0];
    $own = $builder->submit($builder->createDraft($this->fixture->merchant, [$t0->id]));
    $paid = $allocator->recordBankPayment($own, Laari::of(2_750), 'BML-REJECT-NET-PAID');
    $allocator->matchPayment($paid, $this->admin);
    app(ReversalService::class)
        ->reverse($t0, Actor::system(), 'customer_refund', now()->toImmutable());

    $adjustment = Adjustment::query()->sole();
    expect($adjustment->state)->toBe('pending');

    // A batch over the remaining 9,075 nets the credit down to 6,325.
    $settlementId = submitReceipt(6_325, 'BML-REJECT-NET');

    expect($adjustment->refresh()->state)->toBe('applied')
        ->and($adjustment->settlement_id)->toBe($settlementId);

    $receivableBefore = $this->balances->accountBalance(AccountCode::MerchantReceivable);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/settlements/{$settlementId}/reject", ['reason' => 'Transfer never arrived.'])
        ->assertOk();

    // The credit goes back to pending for a LATER batch, and its
    // application-time journal is mirrored so the receivable is whole again.
    expect($adjustment->refresh()->state)->toBe('pending')
        ->and($adjustment->settlement_id)->toBeNull()
        ->and($adjustment->applied_at)->toBeNull()
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe($receivableBefore + 2_750)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    expect(app(Reconciler::class)->run()->status)->toBe('ok');
});

it('lets a rejected bank reference be used again — a burnt reference would strand real money', function () {
    $settlementId = submitReceipt(11_825, 'BML-SAME-REF');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/settlements/{$settlementId}/reject", ['reason' => 'Slip unreadable.'])
        ->assertOk();

    // Same reference, fresh batch: the partial unique index excludes rejected
    // payments precisely so a fixable mistake is fixable.
    $retryId = submitReceipt(11_825, 'BML-SAME-REF');

    expect(Settlement::query()->findOrFail($retryId)->state)->toBe(SettlementState::PaymentReview);
});

it('requires a reason to reject and refuses to reject anything already matched', function () {
    $settlementId = submitReceipt(11_825, 'BML-REJECT-GUARD');
    $paymentId = SettlementPayment::query()->sole()->id;

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/settlements/{$settlementId}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->postJson("/api/admin/payments/{$paymentId}/match")->assertOk();

    // Matched: the lines are allocated and the customers' cashback is
    // confirmed — §13 says corrections after that are adjustments.
    $this->postJson("/api/admin/settlements/{$settlementId}/reject", ['reason' => 'Changed my mind.'])
        ->assertConflict();

    expect(Settlement::query()->findOrFail($settlementId)->lines()->count())->toBe(4);
});

it('refuses a merchant reject and an unauthenticated reject', function () {
    $settlementId = submitReceipt(11_825, 'BML-REJECT-GUARD-2');

    $this->postJson("/api/admin/settlements/{$settlementId}/reject", ['reason' => 'nope'])
        ->assertUnauthorized();

    $this->actingAs($this->fixture->user, 'merchant')
        ->postJson("/api/admin/settlements/{$settlementId}/reject", ['reason' => 'nope'])
        ->assertUnauthorized();

    expect(Settlement::query()->findOrFail($settlementId)->state)->toBe(SettlementState::PaymentReview);
});

it('refuses the same bank reference twice with a 409', function () {
    // First batch claims two lines, leaving two eligible.
    $this->actingAs($this->fixture->user, 'merchant')
        ->post('/api/merchant/settlements', [
            'transaction_ids' => [$this->fixture->transactions[0]->id, $this->fixture->transactions[1]->id],
            'amount' => 4_125,
            'bank_ref' => 'BML-DUPE-1',
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated();

    // A second, DIFFERENT batch over DIFFERENT transactions quoting the same
    // transfer: the per-merchant partial unique index catches it even though
    // the settlement is new — the per-settlement index alone never would.
    $this->post('/api/merchant/settlements', [
        'transaction_ids' => [$this->fixture->transactions[3]->id],
        'amount' => 2_200,
        'bank_ref' => 'BML-DUPE-1',
        'slip' => Slips::png(),
    ])
        ->assertConflict()
        ->assertJsonPath('code', 'duplicate_bank_ref');

    // Only the first batch exists — the loser rolled back whole.
    expect(Settlement::query()->count())->toBe(1)
        ->and(SettlementPayment::query()->count())->toBe(1)
        ->and(Storage::disk('slips')->allFiles())->toHaveCount(1);
});

it('refuses a duplicate reference on the same batch too', function () {
    $settlementId = submitReceipt(5_000, 'BML-DUPE-2');

    $this->actingAs($this->fixture->user, 'merchant')
        ->post("/api/merchant/settlements/{$settlementId}/receipts", [
            'amount' => 6_825,
            'bank_ref' => 'BML-DUPE-2',
            'slip' => Slips::png(),
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'duplicate_bank_ref');

    expect(SettlementPayment::query()->count())->toBe(1);
});
