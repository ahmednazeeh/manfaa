<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\WalletFunding;
use App\Models\AdminUser;
use App\Models\Settlement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
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
    $this->admin = AdminUser::factory()->create();

    $builder = app(SettlementBuilder::class);
    $this->settlement = $builder->createDraft($this->fixture->merchant);
    $builder->submit($this->settlement);
    $this->settlement->refresh();

    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Records a bank payment against the batch and matches it as the admin.
 */
function allocPayAndMatch(Settlement $settlement, int $amountLaari): Settlement
{
    $allocator = app(SettlementAllocator::class);
    $payment = $allocator->recordBankPayment($settlement->refresh(), Laari::of($amountLaari), 'BML-'.$amountLaari);

    return $allocator->matchPayment($payment, test()->admin);
}

/**
 * The debit total of the settlement-referenced journal with the given
 * description — 0 when no such journal was posted.
 */
function allocJournalDebits(int $settlementId, string $description): int
{
    return (int) DB::table('ledger_entries')
        ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
        ->where('ledger_journals.reference_type', 'settlement')
        ->where('ledger_journals.reference_id', $settlementId)
        ->where('ledger_journals.description', $description)
        ->sum('ledger_entries.debit_laari');
}

it('allocates a partial payment oldest-first in whole lines and parks the remainder in the wallet', function () {
    $settlement = allocPayAndMatch($this->settlement, 6000);

    // 6000 covers 2750 + 1375 = 4125; the 5500 line is unaffordable, so
    // allocation stops there — strict oldest-first, never pro-rata.
    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and($settlement->amount_received_laari)->toBe(6000);

    [$first, $second, $third, $fourth] = array_map(
        fn ($transaction) => $transaction->refresh(),
        $this->fixture->transactions,
    );

    expect($first->state)->toBe(TransactionState::Confirmed)
        ->and($first->confirmed_at)->not->toBeNull()
        ->and($second->state)->toBe(TransactionState::Confirmed)
        ->and($third->state)->toBe(TransactionState::PayableUnfunded)
        ->and($third->confirmed_at)->toBeNull()
        ->and($fourth->state)->toBe(TransactionState::PayableUnfunded);

    // Every confirmation went through the state machine.
    $event = $first->events()->where('to_state', 'confirmed')->sole();
    expect($event->reason_code)->toBe('settlement_allocated')
        ->and($event->actor_type)->toBe('admin')
        ->and($event->actor_id)->toBe($this->admin->id);

    // Only the two allocated lines carry allocated_at.
    expect($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(2);

    // Ledger: cash books exactly the allocated 4125; the 1875 remainder is a
    // wallet credit; the receivable drops by exactly 4125.
    expect(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(4125)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(1875)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(11825 - 4125)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(1875)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('forgives a sub-MVR-1 shortfall: pay 11780 against 11825 and the whole batch confirms', function () {
    $settlement = allocPayAndMatch($this->settlement, 11780);

    // Shortfall 45 < 100 laari: every line allocates, the batch settles.
    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(11780)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0);

    foreach ($this->fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at)->not->toBeNull();
    }

    // Cash books what cash covered (11780); forgiveness has its own posting
    // for the 45 — the receivable nets to zero, the platform absorbs the gap
    // as expense, and no laari ever touches bad debt.
    expect(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(11780)
        ->and(allocJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(45)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(45)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // No wallet credit was created — nothing was left over.
    expect($this->fixture->merchant->wallet()->first()?->balance_laari ?? 0)->toBe(0);
});

it('does not forgive a shortfall of MVR 1 or more: pay 11700 and the last line stays pending', function () {
    $settlement = allocPayAndMatch($this->settlement, 11700);

    // Shortfall 125 >= 100: only lines fully covered by 11700 allocate —
    // 2750 + 1375 + 5500 = 9625; the 2200 line stays pending and the 2075
    // remainder becomes a wallet credit.
    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(3);

    expect($this->fixture->transactions[2]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($this->fixture->transactions[3]->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    expect(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(9625)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(11700 - 9625)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(11825 - 9625)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::PlatformFundedRewards))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('forgives at exactly 99 laari short', function () {
    $settlement = allocPayAndMatch($this->settlement, 11726);

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and(allocJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(99)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(99)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('does not forgive at exactly 100 laari short', function () {
    $settlement = allocPayAndMatch($this->settlement, 11725);

    // due - received = 100: NOT under MVR 1, so no forgiveness. Covered
    // lines: 2750 + 1375 + 5500 = 9625; remainder 2100 to the wallet.
    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(3)
        ->and(allocJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::PlatformFundedRewards))->toBe(0)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(11725 - 9625)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('credits an overpayment to the wallet: pay 12000 and 175 becomes merchant credit', function () {
    $settlement = allocPayAndMatch($this->settlement, 12000);

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(12000)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0);

    foreach ($this->fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed);
    }

    expect(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(11825)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(175)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(175)
        ->and($this->balances->accountBalance(AccountCode::PlatformFundedRewards))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('applies a second payment cumulatively, consuming the parked wallet remainder', function () {
    allocPayAndMatch($this->settlement, 6000);
    $settlement = allocPayAndMatch($this->settlement, 5825);

    // Cumulative received 11825 == due: the remaining 5500 + 2200 allocate,
    // funded by the second payment's 5825 cash plus the 1875 the first
    // payment parked in the wallet — which drains back to zero.
    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(11825)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0);

    expect($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(0)
        ->and(allocJournalDebits($settlement->id, 'Wallet settlement applied'))->toBe(1875)
        ->and(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(4125 + 5825)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('matches a second pending payment after the first match left payment_review', function () {
    $allocator = app(SettlementAllocator::class);

    // Both transfers are recorded before either is matched — the queue holds
    // two pending payments on one batch.
    $p1 = $allocator->recordBankPayment($this->settlement, Laari::of(5000), 'BML-SPLIT-1');
    $p2 = $allocator->recordBankPayment($this->settlement->refresh(), Laari::of(6825), 'BML-SPLIT-2');

    $settlement = $allocator->matchPayment($p1, $this->admin);

    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(2);

    // The batch is no longer in payment_review, but P2 is real money and
    // must still be matchable — it completes the batch, consuming the 875
    // the first match parked in the wallet.
    $settlement = $allocator->matchPayment($p2, $this->admin);

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(11825)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(0)
        ->and(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(4125 + 6825)
        ->and(allocJournalDebits($settlement->id, 'Wallet settlement applied'))->toBe(875)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('matches a duplicate payment against a settled batch as a pure wallet credit', function () {
    $allocator = app(SettlementAllocator::class);

    // The merchant transferred twice by mistake; the admin recorded both
    // while the batch sat in payment_review.
    $p1 = $allocator->recordBankPayment($this->settlement, Laari::of(11825), 'BML-DUP-1');
    $p2 = $allocator->recordBankPayment($this->settlement->refresh(), Laari::of(11825), 'BML-DUP-2');

    $settlement = $allocator->matchPayment($p1, $this->admin);
    expect($settlement->state)->toBe(SettlementState::Settled);

    // The duplicate is real bank cash: it allocates nothing, books as a §7
    // overpayment wallet credit, and the batch stays settled.
    $settlement = $allocator->matchPayment($p2, $this->admin);

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(23650)
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(4)
        ->and($p2->refresh()->state)->toBe('matched')
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(11825)
        ->and(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(11825)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(11825)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('caps the applied wallet remainder at the actual balance when the merchant already spent it', function () {
    $allocator = app(SettlementAllocator::class);
    $builder = app(SettlementBuilder::class);

    // P1 = 6000: allocates 2750 + 1375, parks 1875 in the SPENDABLE wallet.
    allocPayAndMatch($this->settlement, 6000);
    expect($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(1875);

    // The merchant legitimately spends part of that credit: a second batch
    // (due 1375) settles from the wallet, leaving only 500 behind.
    $now = CarbonImmutable::now('UTC');
    $extra = Transaction::factory()->for($this->fixture->merchant)->create([
        'customer_id' => $this->fixture->customer->id,
        'state' => TransactionState::PayableUnfunded,
        'eligible_laari' => 50000,
        'cashback_laari' => 1000,
        'fee_laari' => 375,
        'fee_gst_laari' => 0,
        'clock_start_at' => $now,
        'due_at' => $now->addDays(15),
    ]);
    app(Postings::class)->accrue(1000, 375, 0, referenceId: $extra->id);

    $batchB = $builder->createDraft($this->fixture->merchant, [$extra->id]);
    $builder->submit($batchB);
    app(WalletFunding::class)->settleFromWallet($batchB->refresh(), $this->fixture->user);

    expect($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(500);

    // P2 = 5825: cumulative received claims 1875 is parked, but only 500 is
    // still there. The match must fund lines from what actually exists —
    // never force the wallet negative and 500 the admin.
    $settlement = allocPayAndMatch($this->settlement, 5825);

    // Funds 5825 + 500 = 6325 cover the 5500 line; 2200 stays pending.
    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(3)
        ->and($this->fixture->transactions[2]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($this->fixture->transactions[3]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(4125 + 5500)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(500 + 325);

    // P3 = 1375 completes the batch: 1375 cash plus the 825 wallet credit
    // fund the final 2200 line exactly, and everything reconciles.
    $settlement = allocPayAndMatch($this->settlement, 1375);

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(0)
        ->and(allocJournalDebits($settlement->id, 'Wallet settlement applied'))->toBe(825)
        ->and(allocJournalDebits($settlement->id, 'Bank settlement received'))->toBe(4125 + 5500 + 1375)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('confirms nothing and refuses to match the same payment twice', function () {
    $allocator = app(SettlementAllocator::class);
    $payment = $allocator->recordBankPayment($this->settlement, Laari::of(11825), 'BML-ONCE');

    expect($this->settlement->refresh()->state)->toBe(SettlementState::PaymentReview)
        ->and($this->fixture->transactions[0]->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    $allocator->matchPayment($payment, $this->admin);

    expect(fn () => $allocator->matchPayment($payment->refresh(), $this->admin))
        ->toThrow(InvalidSettlementStateException::class);

    expect($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0);
});
