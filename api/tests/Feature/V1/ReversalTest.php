<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Settlement\OutstandingSummary;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Models\Adjustment;
use App\Models\Merchant;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    // The §4 batch: four payable transactions, dues 2750/1375/5500/2200 in
    // due-date order, total 11825. Time is frozen at BASE + 4 days.
    $this->fixture = SettlementFixture::payableBatch();
    $this->merchant = $this->fixture->merchant;
    $this->token = $this->merchant->createToken('till', ['transactions:reverse'])->plainTextToken;
});

afterEach(function () {
    Carbon::setTestNow();
});

function postReverse(int $transactionId, ?string $token = null, ?string $key = null): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.($token ?? test()->token),
        'Idempotency-Key' => $key ?? (string) Str::uuid(),
    ])->postJson("/api/v1/transactions/{$transactionId}/reverse", [
        'reason' => 'customer_refund',
        'occurred_at' => now()->subMinutes(5)->toIso8601String(),
    ]);
}

/**
 * Recursively key-sorts a decoded JSON array so replayed bodies (stored in
 * jsonb, which does not preserve object key order) compare value-identical.
 *
 * @param  array<array-key, mixed>  $json
 * @return array<array-key, mixed>
 */
function reversalCanonicalJson(array $json): array
{
    ksort($json);

    return array_map(fn ($value) => is_array($value) ? reversalCanonicalJson($value) : $value, $json);
}

/**
 * Count of 'Cashback accrual reversed' journals for the given reference.
 */
function reversalJournals(string $referenceType, int $referenceId): int
{
    return DB::table('ledger_journals')
        ->where('reference_type', $referenceType)
        ->where('reference_id', $referenceId)
        ->where('description', 'Cashback accrual reversed')
        ->count();
}

/**
 * Count of 'Adjustment credit applied' journals for the given adjustment.
 */
function adjustmentAppliedJournals(int $adjustmentId): int
{
    return DB::table('ledger_journals')
        ->where('reference_type', 'adjustment')
        ->where('reference_id', $adjustmentId)
        ->where('description', 'Adjustment credit applied')
        ->count();
}

it('reverses a pre-confirmation transaction in place and mirrors the accrual journal', function () {
    $transaction = $this->fixture->transactions[0]; // payable_unfunded, in NO settlement

    postReverse($transaction->id)
        ->assertOk()
        ->assertJsonPath('outcome', 'reversed')
        ->assertJsonPath('cause', null)
        ->assertJsonPath('adjustment', null)
        ->assertJsonPath('transaction.state', 'reversed')
        ->assertJsonPath('transaction.reason_code', 'customer_refund')
        // The STORED integers, echoed — never recomputed.
        ->assertJsonPath('transaction.cashback_laari', 2000)
        ->assertJsonPath('transaction.fee_laari', 750);

    $transaction->refresh();
    $balances = new Balances;

    // Receivable now carries only the three untouched lines: 11825 - 2750.
    expect($transaction->state)->toBe(TransactionState::Reversed)
        ->and(reversalJournals('transaction', $transaction->id))->toBe(1)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11825 - 2750)
        ->and(Adjustment::query()->count())->toBe(0);
});

it('creates a pending adjustment instead when the line is locked in a submitted settlement', function () {
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->createDraft($this->merchant);
    $builder->submit($settlement);

    $transaction = $this->fixture->transactions[0]; // due 2750, now locked

    postReverse($transaction->id)
        ->assertOk()
        ->assertJsonPath('outcome', 'adjustment_created')
        ->assertJsonPath('cause', 'locked_in_settlement')
        ->assertJsonPath('adjustment.transaction_id', $transaction->id)
        ->assertJsonPath('adjustment.amount_laari', -2750)
        ->assertJsonPath('adjustment.amount_mvr', '-27.50')
        ->assertJsonPath('transaction.state', 'payable_unfunded');

    $adjustment = Adjustment::query()->sole();

    // Creation is a memo: no ledger yet — reverseAccrual posts only when
    // the adjustment is APPLIED to a settlement draft.
    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($adjustment->state)->toBe('pending')
        ->and($adjustment->settlement_id)->toBeNull()
        ->and($adjustment->cashback_laari)->toBe(-2000)
        ->and($adjustment->fee_laari)->toBe(-750)
        ->and($adjustment->fee_gst_laari)->toBe(0)
        ->and(reversalJournals('transaction', $transaction->id))->toBe(0)
        ->and(reversalJournals('adjustment', $adjustment->id))->toBe(0);

    // The merchant outstanding summary surfaces the un-netted credit.
    $summary = (new OutstandingSummary)->forMerchant($this->merchant);

    expect($summary['pending_adjustments']['count'])->toBe(1)
        ->and($summary['pending_adjustments']['credit_laari'])->toBe(-2750)
        ->and($summary['pending_adjustments']['credit_mvr'])->toBe('-27.50');
});

it('nets the pending adjustment into the next draft, posts its credit journal, and marks it applied', function () {
    $builder = app(SettlementBuilder::class);
    $first = $builder->createDraft($this->merchant);
    $builder->submit($first);

    $locked = $this->fixture->transactions[0]; // due 2750
    postReverse($locked->id)->assertOk()->assertJsonPath('outcome', 'adjustment_created');

    $adjustment = Adjustment::query()->sole();
    $receivableBefore = (new Balances)->accountBalance(AccountCode::MerchantReceivable);

    // A fresh payable sale for the next batch: 200000 @ 200bp/75bp → due 5500.
    $credits = app(ManualCreditService::class);
    $transitions = app(TransitionService::class);
    $fresh = $credits->credit(
        $this->merchant,
        $this->fixture->user,
        $this->fixture->customer->customer_code,
        'INV-2001',
        Laari::of(200000),
        null,
        now()->subHour()->toImmutable(),
    );
    $transitions->makePayable($fresh, Actor::system());

    // While the locking batch is still cancellable (awaiting_payment), the
    // memo stays pending: a cancellation could release the transaction, and
    // an already-netted credit would then double-count its refund.
    $premature = $builder->createDraft($this->merchant);

    expect($adjustment->refresh()->state)->toBe('pending')
        ->and($premature->amount_due_laari)->toBe(5500);

    $builder->cancel($premature);

    // The merchant pays into the locked batch — payment_review is past the
    // point of cancellation, so the transaction's fate is sealed and the
    // credit may net the next draft.
    app(SettlementAllocator::class)->recordBankPayment($first->refresh(), Laari::of(11825), 'BML-REV-11825');

    $next = $builder->createDraft($this->merchant);
    $adjustment->refresh();
    $balances = new Balances;

    expect($next->state)->toBe(SettlementState::Draft)
        // Line totals stay sums of the stored line integers…
        ->and($next->cashback_total_laari)->toBe(4000)
        ->and($next->fee_total_laari)->toBe(1500)
        // …and amount_due nets the stored adjustment credit: 5500 - 2750.
        ->and($next->amount_due_laari)->toBe(5500 - 2750)
        ->and($adjustment->state)->toBe('applied')
        ->and($adjustment->settlement_id)->toBe($next->id)
        ->and($adjustment->applied_at)->not->toBeNull()
        // Application time is when the ledger moves: one credit journal,
        // referenced to the adjustment, from the stored integers — the
        // cashback share charged to Platform-Funded Rewards (the reward
        // survives), the fee share reversing revenue, never the liability.
        ->and(adjustmentAppliedJournals($adjustment->id))->toBe(1)
        ->and(reversalJournals('adjustment', $adjustment->id))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe($receivableBefore + 5500 - 2750)
        ->and($balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(2000)
        // The liability still carries every live reward in full: 8600 from
        // the §4 batch + 4000 from INV-2001 — the adjustment released none.
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(8600 + 4000);

    // Netted and applied — the summary no longer shows it pending.
    $summary = (new OutstandingSummary)->forMerchant($this->merchant);

    expect($summary['pending_adjustments']['count'])->toBe(0)
        ->and($summary['pending_adjustments']['credit_laari'])->toBe(0);
});

it('routes a reversal of a confirmed transaction to the adjustment path', function () {
    $transitions = app(TransitionService::class);
    $transaction = $this->fixture->transactions[1]; // due 1375
    $transitions->confirm($transaction, Actor::system());

    postReverse($transaction->id)
        ->assertOk()
        ->assertJsonPath('outcome', 'adjustment_created')
        ->assertJsonPath('cause', 'already_confirmed')
        ->assertJsonPath('adjustment.amount_laari', -1375)
        ->assertJsonPath('transaction.state', 'confirmed');

    expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and(Adjustment::query()->sole()->state)->toBe('pending')
        ->and(reversalJournals('transaction', $transaction->id))->toBe(0);
});

it('does not create a second adjustment when the same transaction is reversed twice', function () {
    $transitions = app(TransitionService::class);
    $transaction = $this->fixture->transactions[1];
    $transitions->confirm($transaction, Actor::system());

    postReverse($transaction->id)->assertOk();
    $first = Adjustment::query()->sole();

    postReverse($transaction->id)
        ->assertOk()
        ->assertJsonPath('outcome', 'adjustment_created')
        ->assertJsonPath('adjustment.id', $first->id);

    expect(Adjustment::query()->count())->toBe(1);
});

it('routes a reversal of a paid transaction to the adjustment path (already_confirmed)', function () {
    // Published contract (docs/openapi.yaml): cause already_confirmed covers
    // "confirmed or already paid out"; 409 invalid_state is reserved for the
    // terminal states reversed and written_off. A post-payout refund becomes
    // the merchant's credit; the customer's payout offsets against future
    // earnings (§11), never a clawback.
    $transitions = app(TransitionService::class);
    $transaction = $this->fixture->transactions[2]; // due 5500
    $transitions->confirm($transaction, Actor::system());
    $transitions->markPaid($transaction, Actor::system());

    postReverse($transaction->id)
        ->assertOk()
        ->assertJsonPath('outcome', 'adjustment_created')
        ->assertJsonPath('cause', 'already_confirmed')
        ->assertJsonPath('adjustment.amount_laari', -5500)
        ->assertJsonPath('transaction.state', 'paid');

    expect($transaction->refresh()->state)->toBe(TransactionState::Paid)
        ->and(Adjustment::query()->sole()->state)->toBe('pending')
        ->and(reversalJournals('transaction', $transaction->id))->toBe(0);
});

it('returns 409 invalid_state for an already reversed transaction', function () {
    $transaction = $this->fixture->transactions[0];
    postReverse($transaction->id)->assertOk();

    postReverse($transaction->id)
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_state')
        ->assertJsonPath('error.meta.state', 'reversed');
});

it("answers 404 transaction_not_found for another merchant's transaction id", function () {
    $other = SettlementFixture::payableBatch('511111');
    Carbon::setTestNow(Carbon::parse(SettlementFixture::BASE)->addDays(4));

    postReverse($other->transactions[0]->id)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'transaction_not_found');

    expect($other->transactions[0]->refresh()->state)->toBe(TransactionState::PayableUnfunded);
});

it('returns 403 when the token lacks the transactions:reverse ability', function () {
    $writeOnly = $this->merchant->createToken('writer', ['transactions:write'])->plainTextToken;

    postReverse($this->fixture->transactions[0]->id, $writeOnly)->assertForbidden();

    expect($this->fixture->transactions[0]->refresh()->state)->toBe(TransactionState::PayableUnfunded);
});

it('replays a reversal under the same Idempotency-Key without reversing twice', function () {
    $transaction = $this->fixture->transactions[0];
    $key = (string) Str::uuid();

    $first = postReverse($transaction->id, key: $key)->assertOk();

    $replay = postReverse($transaction->id, key: $key)
        ->assertOk()
        ->assertHeader('Idempotency-Replay', 'true');

    expect(reversalCanonicalJson($replay->json()))->toBe(reversalCanonicalJson($first->json()))
        ->and(reversalJournals('transaction', $transaction->id))->toBe(1)
        ->and($transaction->refresh()->state)->toBe(TransactionState::Reversed);
});

it('reverses a transaction sitting in a DRAFT settlement and releases its line', function () {
    $builder = app(SettlementBuilder::class);
    $draft = $builder->createDraft($this->merchant); // all four lines, still draft

    $transaction = $this->fixture->transactions[0]; // due 2750

    postReverse($transaction->id)
        ->assertOk()
        ->assertJsonPath('outcome', 'reversed');

    $draft->refresh();

    // §7 locks only non-draft batches: the draft gives the line back and
    // its totals drop to the three remaining lines.
    expect($draft->lines()->count())->toBe(3)
        ->and($draft->amount_due_laari)->toBe(11825 - 2750)
        ->and(reversalJournals('transaction', $transaction->id))->toBe(1);
});
