<?php

declare(strict_types=1);

use App\Domain\Adjustment\InvalidReversalStateException;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Standing\Reconciler;
use App\Models\Adjustment;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->fixture = SettlementFixture::payableBatch();
    $this->builder = app(SettlementBuilder::class);
    $this->reversals = app(ReversalService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function healingJournals(int $transactionId): int
{
    return DB::table('ledger_journals')
        ->where('reference_type', 'transaction')
        ->where('reference_id', $transactionId)
        ->where('description', 'Cashback accrual reversed')
        ->count();
}

/*
 * §7 regression: a reversal against a line locked in a submitted batch
 * becomes a pending credit memo — but nothing reconciled that memo with the
 * transaction's later fate. If the locking batch was CANCELLED, the
 * transaction went back to payable_unfunded while the memo lived on:
 * a vendor re-send then reversed the sale in place AND the memo later
 * netted a batch (double credit); or, with no re-send, the refunded sale
 * rejoined the next draft as a live line and confirmed — paying cashback on
 * returned goods; or it aged into write-off while the memo still promised a
 * credit. Cancellation now executes the deferred reversal: the transaction
 * reverses in place, the accrual is mirrored once, and the memo is voided.
 */

it('reverses the released transaction and voids the memo when the locking batch is cancelled', function () {
    $t0 = $this->fixture->transactions[0]; // due 2750

    $settlement = $this->builder->createDraft($this->fixture->merchant);
    $this->builder->submit($settlement);

    $outcome = $this->reversals->reverse($t0, Actor::system(), 'customer_refund', now()->toImmutable());

    expect($outcome->outcome)->toBe('adjustment_created')
        ->and($outcome->cause)->toBe('locked_in_settlement');

    $this->builder->cancel($settlement);

    $adjustment = Adjustment::query()->sole();
    $balances = new Balances;

    // The deferred half of the vendor's reversal ran at cancellation.
    expect($t0->refresh()->state)->toBe(TransactionState::Reversed)
        ->and($t0->reason_code)->toBe('customer_refund')
        ->and($adjustment->state)->toBe('voided')
        ->and($adjustment->voided_at)->not->toBeNull()
        ->and(healingJournals($t0->id))->toBe(1)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11825 - 2750);

    // The other three lines are released, live, and unharmed.
    foreach ([1, 2, 3] as $i) {
        expect($this->fixture->transactions[$i]->refresh()->state)->toBe(TransactionState::PayableUnfunded);
    }

    // A vendor re-send now meets the terminal state — 409 invalid_state per
    // contract, never a second reversal.
    expect(fn () => $this->reversals->reverse($t0->refresh(), Actor::system(), 'customer_refund', now()->toImmutable()))
        ->toThrow(InvalidReversalStateException::class);

    expect(healingJournals($t0->id))->toBe(1);

    // The next draft holds only the three live sales — the voided memo nets
    // nothing, the reversed sale is not billed.
    $next = $this->builder->createDraft($this->fixture->merchant);

    expect($next->lines()->count())->toBe(3)
        ->and($next->amount_due_laari)->toBe(11825 - 2750)
        ->and($adjustment->refresh()->state)->toBe('voided');

    // The daily invariant agrees end to end.
    $run = app(Reconciler::class)->run();

    expect($run->status)->toBe('ok')->and($run->issues)->toBeNull();
});
