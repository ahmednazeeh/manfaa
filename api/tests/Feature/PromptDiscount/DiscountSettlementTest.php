<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Platform\InvalidSettingException;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementPreview;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\WalletFunding;
use App\Domain\Standing\Reconciler;
use App\Models\AdminUser;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PromptDiscount\PromptFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->admin = AdminUser::factory()->create();
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Build and submit a settle-all batch — the discount is decided at submit. */
function pdSubmitAll(PromptFixture $fixture): Settlement
{
    $builder = app(SettlementBuilder::class);

    return $builder->submit($builder->createDraft($fixture->merchant))->refresh();
}

function pdPayAndMatch(Settlement $settlement, int $amountLaari, string $bankRef): Settlement
{
    $allocator = app(SettlementAllocator::class);
    $payment = $allocator->recordBankPayment($settlement->refresh(), Laari::of($amountLaari), $bankRef);

    return $allocator->matchPayment($payment, test()->admin)->refresh();
}

/** Debits posted against this settlement under the given journal description. */
function pdJournalDebits(int $settlementId, string $description): int
{
    return (int) DB::table('ledger_entries')
        ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
        ->where('ledger_journals.reference_type', 'settlement')
        ->where('ledger_journals.reference_id', $settlementId)
        ->where('ledger_journals.description', $description)
        ->sum('ledger_entries.debit_laari');
}

function pdDiscountJournals(int $settlementId): int
{
    return (int) DB::table('ledger_journals')
        ->where('reference_type', 'settlement')
        ->where('reference_id', $settlementId)
        ->where('description', 'Prompt-payment fee discount')
        ->count();
}

it('settles every line when the merchant pays the discounted amount, with no residue and nothing forgiven', function () {
    $fixture = PromptFixture::singleLine();
    $settlement = pdSubmitAll($fixture);

    // 2,750 of lines − 38 of discount = 2,712 to transfer (hand-derived in
    // DiscountEngineTest: fee 750 × 500bp, ceiling).
    expect($settlement->fee_total_laari)->toBe(750)
        ->and($settlement->discount_laari)->toBe(38)
        ->and($settlement->discount_rate_bp)->toBe(500)
        ->and($settlement->discount_reason)->toBe('eligible')
        ->and($settlement->discount_posted_laari)->toBe(0) // nothing allocated yet
        ->and($settlement->amount_due_laari)->toBe(2_712);

    $settlement = pdPayAndMatch($settlement, 2_712, 'BML-DISCOUNTED');

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($settlement->discount_posted_laari)->toBe(38)
        ->and($fixture->transactions[0]->refresh()->state)->toBe(TransactionState::Confirmed);

    // The receivable nets to EXACTLY zero: 2,712 of cash plus 38 of
    // discount against 2,750 accrued. No forgiveness posting exists — the
    // batch was not short, it was discounted.
    expect(pdJournalDebits($settlement->id, 'Bank settlement received'))->toBe(2_712)
        ->and(pdJournalDebits($settlement->id, 'Prompt-payment fee discount'))->toBe(38)
        ->and(pdJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(0)
        // Revenue is reduced by exactly the discount, and the customer's
        // cashback liability is untouched (PLAN §1).
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(750 - 38)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2_000)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // Nothing is left over anywhere: no wallet credit, no residue.
    expect(DB::table('wallet_transactions')->count())->toBe(0);
});

it('posts the discount as DR platform fee revenue / CR merchant receivable — not bad debt, not platform-funded rewards', function () {
    $fixture = PromptFixture::singleLine();
    $settlement = pdPayAndMatch(pdSubmitAll($fixture), 2_712, 'BML-LEDGER');

    $journal = DB::table('ledger_journals')
        ->where('reference_type', 'settlement')
        ->where('reference_id', $settlement->id)
        ->where('description', 'Prompt-payment fee discount')
        ->sole();

    $entries = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('journal_id', $journal->id)
        ->get(['ledger_accounts.code', 'debit_laari', 'credit_laari']);

    expect($entries->map(fn ($e) => [$e->code, (int) $e->debit_laari, (int) $e->credit_laari])->all())
        ->toEqualCanonicalizing([
            [AccountCode::PlatformFeeRevenue->value, 38, 0],
            [AccountCode::MerchantReceivable->value, 0, 38],
        ])
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(0);
});

it('re-decides the discount at SUBMIT: a preview granted at day 1 is withdrawn once the clock moves', function () {
    $fixture = PromptFixture::fourLines();

    // Day 1: the preview says 162 off, 11,663 to transfer.
    $preview = app(SettlementPreview::class)->for($fixture->merchant, null);

    expect($preview['discount']['eligible'])->toBeTrue()
        ->and($preview['discount_laari'])->toBe(162)
        ->and($preview['amount_due_laari'])->toBe(11_663);

    // The merchant sits on it. Ten days later they finally submit — the
    // preview's answer is advisory and is NOT trusted.
    Carbon::setTestNow(CarbonImmutable::parse(PromptFixture::CLOCK_START)->addDays(10));

    $settlement = pdSubmitAll($fixture);

    expect($settlement->discount_laari)->toBe(0)
        ->and($settlement->discount_rate_bp)->toBeNull()
        ->and($settlement->discount_reason)->toBe('line_too_old')
        ->and($settlement->amount_due_laari)->toBe(11_825);

    // They transfer the stale figure and fall 162 short — over the MVR 1
    // forgiveness threshold, so the oldest-first walk simply stops.
    $settlement = pdPayAndMatch($settlement, 11_663, 'BML-STALE');

    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and(pdDiscountJournals($settlement->id))->toBe(0)
        ->and(pdJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('withdraws the discount at submit when a new sale becomes payable after the preview', function () {
    $fixture = PromptFixture::fourLines();

    expect(app(SettlementPreview::class)->for($fixture->merchant, null)['discount']['eligible'])
        ->toBeTrue();

    // A till POSTs one more sale, and the sweep puts it on the clock, between
    // the preview and the submit. The batch would now leave it behind.
    $extra = $fixture->addPayable(50_000, CarbonImmutable::now('UTC'));

    // Submit only the ORIGINAL four (the selection the merchant previewed).
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant, array_slice($fixture->transactionIds(), 0, 4)))->refresh();

    expect($settlement->discount_laari)->toBe(0)
        ->and($settlement->discount_reason)->toBe('not_all_outstanding')
        ->and($settlement->amount_due_laari)->toBe(11_825)
        ->and($extra->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // Settling EVERYTHING instead — including the new line — earns it.
    app(SettlementBuilder::class)->cancel($settlement);

    $all = pdSubmitAll($fixture);

    expect($all->fee_total_laari)->toBe(3_225 + 375)
        ->and($all->discount_laari)->toBe(intdiv((3_225 + 375) * 500 + 9999, 10000))
        ->and($all->discount_laari)->toBe(180)
        ->and($all->discount_reason)->toBe('eligible');
});

it('discounts a wallet settlement exactly as it discounts a transfer', function () {
    $fixture = PromptFixture::fourLines();

    app(WalletFunding::class)->recordTopUp($fixture->merchant, Laari::of(20_000), 'BML-TOPUP');

    $settlement = app(SettlementBuilder::class)
        ->createAndSettleFromWallet($fixture->merchant, $fixture->user, null)
        ->refresh();

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->funding_method)->toBe('wallet')
        ->and($settlement->discount_laari)->toBe(162)
        ->and($settlement->discount_posted_laari)->toBe(162)
        ->and($settlement->amount_due_laari)->toBe(11_663)
        ->and($settlement->amount_received_laari)->toBe(11_663)
        // Only the DISCOUNTED amount leaves the wallet.
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(20_000 - 11_663)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0);

    expect(pdJournalDebits($settlement->id, 'Wallet settlement applied'))->toBe(11_663)
        ->and(pdJournalDebits($settlement->id, 'Prompt-payment fee discount'))->toBe(162)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225 - 162)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('posts the discount ONCE across a partial payment and its completion', function () {
    $fixture = PromptFixture::fourLines();
    $settlement = pdSubmitAll($fixture);

    expect($settlement->amount_due_laari)->toBe(11_663);

    // 6,000 of the merchant's own money covers the 2,750 and 1,375 lines
    // (4,125); the 1,875 remainder parks in the wallet. The 162 of relief is
    // NOT spent here — this match leaves two lines behind, and the discount
    // was granted for clearing the board.
    $settlement = pdPayAndMatch($settlement, 6_000, 'BML-PART-1');

    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(2)
        ->and($settlement->discount_posted_laari)->toBe(0)
        ->and(pdDiscountJournals($settlement->id))->toBe(0)
        ->and(pdJournalDebits($settlement->id, 'Bank settlement received'))->toBe(4_125)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(1_875)
        // Still granted, still on the row, waiting for the completion.
        ->and($settlement->discount_laari)->toBe(162)
        // Receivable down by exactly the two allocated lines.
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11_825 - 4_125);

    // The rest: 7,700 of lines, 1,875 already in the wallet and 162 of
    // discount → 5,663 of cash, which is what the merchant still owed on the
    // discounted total (11,663 − 6,000).
    $settlement = pdPayAndMatch($settlement, 5_663, 'BML-PART-2');

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->discount_posted_laari)->toBe(162)
        // Still ONE discount journal, still 162 — never 324.
        ->and(pdDiscountJournals($settlement->id))->toBe(1)
        ->and(pdJournalDebits($settlement->id, 'Prompt-payment fee discount'))->toBe(162)
        ->and(pdJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225 - 162)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('withholds the whole discount from a partial payment, and gives up nothing if the merchant walks away', function () {
    // The relief is granted for CLEARING THE BOARD. A merchant who transfers
    // a fraction of the batch has not cleared it, so the platform must not
    // have spent its own revenue on the fraction — least of all ahead of the
    // merchant's cash, which is what a discount consumed first amounts to.
    $fixture = PromptFixture::fourLines();
    $settlement = pdSubmitAll($fixture);

    expect($settlement->discount_laari)->toBe(162)
        ->and($settlement->amount_due_laari)->toBe(11_663);

    // 2,588 laari: 162 short of the oldest line on its own (2,750).
    $settlement = pdPayAndMatch($settlement, 2_588, 'BML-WALKAWAY');

    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        // Not one laari of relief has reached the ledger, and fee revenue is
        // whole: the batch that would have earned it never settled.
        ->and($settlement->discount_posted_laari)->toBe(0)
        ->and(pdDiscountJournals($settlement->id))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225)
        // The merchant's money is not lost either — it could not cover a
        // whole line (§7), so it parks in the wallet for the next match.
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(0)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(2_588)
        ->and(pdJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11_825)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // Completing the batch releases it in full, and the merchant's total
    // outlay is still exactly the discounted figure.
    $settlement = pdPayAndMatch($settlement, 11_663 - 2_588, 'BML-WALKAWAY-2');

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(11_663)
        ->and($settlement->discount_posted_laari)->toBe(162)
        ->and(pdDiscountJournals($settlement->id))->toBe(1)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and(pdJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225 - 162)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('refuses the discount at submit when a line has no settlement clock, and charges the full fee', function () {
    // The §13b legacy shape: a payable row with clock_start_at and due_at
    // both null. It never ages out of payable_unfunded, so an age gate that
    // scored it 0 would hand this merchant the discount on every batch they
    // ever submit.
    $fixture = PromptFixture::fourLines();

    DB::table('transactions')
        ->where('id', $fixture->transactions[0]->id)
        ->update(['clock_start_at' => null, 'due_at' => null]);

    $settlement = pdSubmitAll($fixture);

    expect($settlement->discount_laari)->toBe(0)
        ->and($settlement->discount_rate_bp)->toBeNull()
        ->and($settlement->discount_reason)->toBe('clock_not_started')
        ->and($settlement->amount_due_laari)->toBe(11_825);

    $settlement = pdPayAndMatch($settlement, 11_825, 'BML-NO-CLOCK');

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and(pdDiscountJournals($settlement->id))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('forgives a sub-MVR-1 shortfall on a REFUSED batch too — the §7 rule is blind to why', function () {
    // Reviewed and kept as-is. The merchant previewed a 38-laari discount,
    // a second sale became payable before they submitted, and submit
    // withdrew the grant — so the transfer they had already made is 38 short
    // of the full 2,750. §7 forgives any remaining balance under MVR 1
    // whatever caused it, and §8 books a forgiven shortfall to
    // Platform-Funded Rewards Expense; the alternative is stranding a
    // customer's cashback over MVR 0.38 and asking for another bank
    // transfer to close it. What the refusal DOES cost the merchant is the
    // batch's own accounting: discount_laari stays 0, nothing is booked as a
    // sales discount against fee revenue, and the exposure is bounded by the
    // same < 100 laari the platform absorbs for anyone.
    $fixture = PromptFixture::singleLine();
    $fixture->addPayable(50_000, CarbonImmutable::now('UTC'));

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant, [$fixture->transactions[0]->id]))->refresh();

    expect($settlement->discount_laari)->toBe(0)
        ->and($settlement->discount_reason)->toBe('not_all_outstanding')
        ->and($settlement->amount_due_laari)->toBe(2_750);

    $settlement = pdPayAndMatch($settlement, 2_712, 'BML-REFUSED-SHORT');

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->discount_posted_laari)->toBe(0)
        ->and(pdDiscountJournals($settlement->id))->toBe(0)
        // Booked as the forgiveness it is, never as a discount that was
        // refused: fee revenue is whole, the gap sits in the §8 account.
        ->and(pdJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(38)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(750 + 375)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(38)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('never posts the discount twice when a second payment is matched against a settled batch', function () {
    $fixture = PromptFixture::singleLine();
    $settlement = pdSubmitAll($fixture);

    // The merchant's bank sends the same transfer twice, under two
    // references, before an admin looks at either.
    $allocator = app(SettlementAllocator::class);
    $first = $allocator->recordBankPayment($settlement, Laari::of(2_712), 'BML-FIRST');
    $second = $allocator->recordBankPayment($settlement->refresh(), Laari::of(2_712), 'BML-DUPLICATE');

    $settlement = $allocator->matchPayment($first, $this->admin)->refresh();

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and(pdDiscountJournals($settlement->id))->toBe(1);

    // Matching the second one allocates nothing — it is a pure §7 wallet
    // credit, and emphatically not a second discount.
    $settlement = $allocator->matchPayment($second, $this->admin)->refresh();

    expect(pdDiscountJournals($settlement->id))->toBe(1)
        ->and(pdJournalDebits($settlement->id, 'Prompt-payment fee discount'))->toBe(38)
        ->and($settlement->discount_posted_laari)->toBe(38)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(2_712)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(750 - 38)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // Matching an already-matched payment is refused outright.
    expect(fn () => app(SettlementAllocator::class)->matchPayment($first->refresh(), $this->admin))
        ->toThrow(InvalidSettlementStateException::class);

    expect(pdDiscountJournals($settlement->id))->toBe(1);
});

it('leaves no discount journal behind when the receipt is rejected', function () {
    $fixture = PromptFixture::singleLine();
    $settlement = pdSubmitAll($fixture);

    expect($settlement->discount_laari)->toBe(38);

    app(SettlementAllocator::class)->recordBankPayment($settlement, Laari::of(2_712), 'BML-UNVERIFIED');

    app(SettlementBuilder::class)->reject($settlement->refresh(), $this->admin, 'No such transfer reached the account.');

    // The batch is cancelled, its line released, and the ledger never saw
    // the discount — which is exactly why it posts at allocation and not at
    // submit.
    expect($settlement->refresh()->state)->toBe(SettlementState::Cancelled)
        ->and(pdDiscountJournals($settlement->id))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(750)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(2_750)
        ->and($fixture->transactions[0]->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // A fresh batch re-earns it from scratch.
    expect(pdSubmitAll($fixture)->discount_laari)->toBe(38);
});

it('behaves exactly as it did before the incentive when the rate is zero', function () {
    $fixture = PromptFixture::fourLines(rateBp: 0);
    $settlement = pdSubmitAll($fixture);

    expect($settlement->discount_laari)->toBe(0)
        ->and($settlement->discount_rate_bp)->toBeNull()
        ->and($settlement->discount_reason)->toBe('disabled')
        ->and($settlement->amount_due_laari)->toBe(11_825);

    $settlement = pdPayAndMatch($settlement, 11_825, 'BML-NO-DISCOUNT');

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and(pdDiscountJournals($settlement->id))->toBe(0)
        ->and(pdJournalDebits($settlement->id, 'Bank settlement received'))->toBe(11_825)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225)
        ->and(DB::table('wallet_transactions')->count())->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('parks the difference in the wallet when a merchant pays the UNdiscounted amount', function () {
    $fixture = PromptFixture::singleLine();
    $settlement = pdPayAndMatch(pdSubmitAll($fixture), 2_750, 'BML-OVERPAID');

    // §7: overpayment is a merchant credit on the next batch, never a
    // refund — and the discount still posts, because it still funded 38 of
    // the 2,750 the batch allocated.
    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and(pdJournalDebits($settlement->id, 'Bank settlement received'))->toBe(2_712)
        ->and(pdJournalDebits($settlement->id, 'Prompt-payment fee discount'))->toBe(38)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(38)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('still forgives a sub-MVR-1 shortfall on a discounted batch, and forgives only the real gap', function () {
    $fixture = PromptFixture::fourLines();
    $settlement = pdSubmitAll($fixture);

    // 11,663 due, transferred 45 laari short.
    $settlement = pdPayAndMatch($settlement, 11_618, 'BML-SHORT');

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and(pdJournalDebits($settlement->id, 'Prompt-payment fee discount'))->toBe(162)
        ->and(pdJournalDebits($settlement->id, 'Settlement shortfall forgiven'))->toBe(45)
        ->and(pdJournalDebits($settlement->id, 'Bank settlement received'))->toBe(11_618)
        // 11,618 + 162 + 45 = 11,825: the receivable closes exactly.
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(45)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('keeps the daily reconciliation clean with discounts on the ledger', function () {
    $fixture = PromptFixture::fourLines();
    pdPayAndMatch(pdSubmitAll($fixture), 11_663, 'BML-RECONCILE');

    $run = app(Reconciler::class)->run();

    expect($run->status)->toBe('ok')
        ->and($run->issues)->toBeNull()
        ->and($run->totals['receivable'])->toBe(['derived_laari' => 0, 'ledger_laari' => 0])
        ->and($run->totals['revenue'])->toBe(['derived_laari' => 3_225 - 162, 'ledger_laari' => 3_225 - 162])
        // The customers' rewards are whole: the discount touched none of them.
        ->and($run->totals['liability'])->toBe(['derived_laari' => 8_600, 'ledger_laari' => 8_600]);
});

it('caps the discount at what the batch still owes after a §7 credit', function () {
    // A refund on a confirmed sale becomes a pending credit memo (§7). The
    // next batch nets it in FIRST, and the discount can only ever come off
    // what is LEFT — never out of the platform's pocket.
    $fixture = PromptFixture::singleLine();
    $first = pdPayAndMatch(pdSubmitAll($fixture), 2_712, 'BML-CAP-1');

    expect($first->state)->toBe(SettlementState::Settled);

    app(ReversalService::class)->reverse(
        $fixture->transactions[0]->refresh(),
        Actor::system(),
        'customer_refund',
        CarbonImmutable::now('UTC'),
    );

    // One more sale of the same size: 2,750 of lines against a 2,750 credit
    // leaves nothing due, so the 38-laari discount is capped to zero and the
    // batch settles on the credit alone.
    $second = $fixture->addPayable(100_000, CarbonImmutable::now('UTC'));
    $next = pdSubmitAll($fixture);

    expect($next->discount_laari)->toBe(0)
        ->and($next->discount_rate_bp)->toBeNull()
        ->and($next->amount_due_laari)->toBe(0)
        ->and($next->state)->toBe(SettlementState::Settled)
        ->and($next->discount_posted_laari)->toBe(0)
        ->and(pdDiscountJournals($next->id))->toBe(0)
        ->and($second->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('is bounded by the platform ceiling: 20% is the most a discount can ever be', function () {
    $fixture = PromptFixture::fourLines(rateBp: 2_000);
    $settlement = pdSubmitAll($fixture);

    // 3,225 × 20% = 645 exactly.
    expect($settlement->discount_laari)->toBe(645)
        ->and($settlement->amount_due_laari)->toBe(11_180);

    expect(fn () => app(PlatformConfig::class)->set('prompt_discount_rate_bp', 2_001))
        ->toThrow(InvalidSettingException::class);
});
