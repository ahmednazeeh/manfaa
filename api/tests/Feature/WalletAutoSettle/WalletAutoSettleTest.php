<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Settlement\InsufficientWalletBalanceException;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\WalletAutoSettler;
use App\Domain\Settlement\WalletFunding;
use App\Jobs\SendCustomerSms;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\PromptDiscount\PromptFixture;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The hourly wallet run (owner, 2026-08-24). SettlementFixture pins the
 * prompt discount OFF and prices the §4 batch exactly — dues 2,750 / 1,375 /
 * 5,500 / 2,200 in due order, 11,825 in all — so every "what fits" assertion
 * here is plain arithmetic. The two discount tests use PromptFixture, which
 * leaves the platform at its shipped defaults (500bp, 10 days).
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    // The run announces after commit; nothing here reads the SMS provider.
    Queue::fake();
    $this->balances = new Balances;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function autoSettleTopUp(Merchant $merchant, int $laari): void
{
    static $sequence = 0;

    app(WalletFunding::class)->recordTopUp($merchant, Laari::of($laari), 'BML-AUTO-'.(++$sequence));
}

/**
 * The bodies of every store SMS the run queued, in order. The job keeps
 * its body private; the closure is bound into it to read the one field.
 *
 * @return list<string>
 */
function autoSettleSmsBodies(): array
{
    return Queue::pushed(SendCustomerSms::class)
        ->map(fn (SendCustomerSms $job): string => (fn (): string => $this->body)->call($job))
        ->values()
        ->all();
}

it('settles the whole batch when the balance covers it, signed by the system, and tells the store once', function (): void {
    $fixture = SettlementFixture::payableBatch();
    autoSettleTopUp($fixture->merchant, 20_000);

    $run = app(WalletAutoSettler::class)->run();

    expect($run)->toBe(['checked' => 1, 'settled' => 1, 'skipped' => 0]);

    $settlement = Settlement::query()->sole();

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->funding_method)->toBe('wallet')
        ->and($settlement->amount_due_laari)->toBe(11_825)
        ->and($settlement->amount_received_laari)->toBe(11_825)
        ->and($settlement->lines()->count())->toBe(4)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(8_175);

    foreach ($fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at)->not->toBeNull();

        // Same path as the button, different signature on the event.
        $event = $transaction->events()->where('to_state', 'confirmed')->sole();

        expect($event->reason_code)->toBe('settlement_allocated')
            ->and($event->actor_type)->toBe('system')
            ->and($event->actor_id)->toBeNull();
    }

    // The wallet gave up exactly what the receivable was owed.
    expect($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(8_175)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // One message, carrying the run's three numbers: drawn, count, left.
    Queue::assertPushed(SendCustomerSms::class, 1);

    expect(autoSettleSmsBodies()[0])
        ->toContain('MVR 118.25')
        ->toContain('4 sales')
        ->toContain('MVR 81.75');
});

it('settles the oldest lines that fit and leaves the rest payable — a prefix, never a skip', function (): void {
    $fixture = SettlementFixture::payableBatch();
    // 2,750 + 1,375 = 4,125 fits; + 5,500 = 9,625 does not. The 2,200 line
    // WOULD fit on what is left, but it is younger than the one that did
    // not — it waits.
    autoSettleTopUp($fixture->merchant, 8_000);

    $settlement = app(WalletAutoSettler::class)->settle($fixture->merchant);

    expect($settlement)->not->toBeNull()
        ->and($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(4_125)
        ->and($settlement->lines()->pluck('transaction_id')->all())
        ->toEqualCanonicalizing([$fixture->transactions[0]->id, $fixture->transactions[1]->id])
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(3_875);

    expect($fixture->transactions[0]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($fixture->transactions[1]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($fixture->transactions[2]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($fixture->transactions[3]->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // The remainder is on no batch: eligible for the next run, or for the
    // merchant to settle by bank.
    expect(app(SettlementBuilder::class)->eligibleTransactions($fixture->merchant)->pluck('id')->all())
        ->toEqualCanonicalizing([$fixture->transactions[2]->id, $fixture->transactions[3]->id]);

    expect(autoSettleSmsBodies()[0])
        ->toContain('MVR 41.25')
        ->toContain('2 sales')
        ->toContain('MVR 38.75');

    // A later top-up lets the next run carry on from where it stopped:
    // 3,875 + 5,500 = 9,375 covers 5,500 + 2,200.
    autoSettleTopUp($fixture->merchant, 5_500);

    $run = app(WalletAutoSettler::class)->run();

    expect($run)->toBe(['checked' => 1, 'settled' => 1, 'skipped' => 0])
        ->and(Settlement::query()->count())->toBe(2)
        ->and($fixture->transactions[2]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($fixture->transactions[3]->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(1_675)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    Queue::assertPushed(SendCustomerSms::class, 2);
});

it('does nothing when the store has switched auto-settlement off', function (): void {
    $fixture = SettlementFixture::payableBatch();
    $fixture->merchant->update(['auto_settle_from_wallet' => false]);
    autoSettleTopUp($fixture->merchant, 20_000);

    expect(app(WalletAutoSettler::class)->run())->toBe(['checked' => 0, 'settled' => 0, 'skipped' => 0])
        // The direct call re-reads the switch too — the pre-filter is not
        // the decision.
        ->and(app(WalletAutoSettler::class)->settle($fixture->merchant))->toBeNull()
        ->and(Settlement::query()->count())->toBe(0)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(20_000);

    foreach ($fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded);
    }

    Queue::assertNotPushed(SendCustomerSms::class);
});

it('does nothing on a zero balance, with or without a wallet row', function (): void {
    $fixture = SettlementFixture::payableBatch();

    expect(app(WalletAutoSettler::class)->run())->toBe(['checked' => 0, 'settled' => 0, 'skipped' => 0]);

    MerchantWallet::query()->create(['merchant_id' => $fixture->merchant->id, 'balance_laari' => 0, 'currency' => 'MVR']);

    expect(app(WalletAutoSettler::class)->run())->toBe(['checked' => 0, 'settled' => 0, 'skipped' => 0])
        ->and(app(WalletAutoSettler::class)->settle($fixture->merchant))->toBeNull()
        ->and(Settlement::query()->count())->toBe(0);

    Queue::assertNotPushed(SendCustomerSms::class);
});

it('does nothing when not even the oldest line fits', function (): void {
    $fixture = SettlementFixture::payableBatch();
    autoSettleTopUp($fixture->merchant, 1_000);

    // Checked — the store qualified — but there was nothing it could afford.
    expect(app(WalletAutoSettler::class)->run())->toBe(['checked' => 1, 'settled' => 0, 'skipped' => 0])
        ->and(Settlement::query()->count())->toBe(0)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(1_000);

    Queue::assertNotPushed(SendCustomerSms::class);
});

it('never spends for a store that is not past approval', function (): void {
    $fixture = SettlementFixture::payableBatch();
    autoSettleTopUp($fixture->merchant, 20_000);
    $fixture->merchant->update(['status' => 'pending_review']);

    expect(app(WalletAutoSettler::class)->run())->toBe(['checked' => 0, 'settled' => 0, 'skipped' => 0])
        ->and(app(WalletAutoSettler::class)->settle($fixture->merchant))->toBeNull()
        ->and(Settlement::query()->count())->toBe(0);

    // Suspension is credit control for exactly this debt — a wallet that
    // can pay it down does.
    $fixture->merchant->update(['status' => 'suspended']);

    expect(app(WalletAutoSettler::class)->run())->toBe(['checked' => 1, 'settled' => 1, 'skipped' => 0]);
});

it('draws only the DISCOUNTED due when the batch earns the prompt discount', function (): void {
    $fixture = PromptFixture::fourLines();
    autoSettleTopUp($fixture->merchant, 20_000);

    $settlement = app(WalletAutoSettler::class)->settle($fixture->merchant);

    // 11,825 of lines − 162 of discount (3,225 fee × 500bp, ceiling).
    expect($settlement)->not->toBeNull()
        ->and($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->discount_laari)->toBe(162)
        ->and($settlement->discount_reason)->toBe('eligible')
        ->and($settlement->discount_posted_laari)->toBe(162)
        ->and($settlement->amount_due_laari)->toBe(11_663)
        ->and($settlement->amount_received_laari)->toBe(11_663)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(20_000 - 11_663)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225 - 162)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // The store is told the discounted figure — what actually left.
    expect(autoSettleSmsBodies()[0])
        ->toContain('MVR 116.63')
        ->toContain('4 sales')
        ->toContain('MVR 83.37');
});

/*
 * THE DISCOUNT BAND (owner-approved fix, 2026-08-25). PromptFixture::fourLines
 * prices at 11,825 undiscounted, 3,225 of which is platform fee, so the
 * PLAN §1 discount is ceiling(3,225 x 500bp) = 162 and the whole board costs
 * 11,663. Between those two numbers sits the band the planner used to fall
 * into: enough to clear everything at the price it would actually be charged,
 * not enough line by line — and dropping one line withdrew the discount from
 * the rest.
 */

it('clears the WHOLE board when the balance covers its discounted due, and earns the discount', function (): void {
    $fixture = PromptFixture::fourLines();
    // Above the discounted 11,663, below the undiscounted 11,825 — the band.
    autoSettleTopUp($fixture->merchant, 11_700);

    $settlement = app(WalletAutoSettler::class)->settle($fixture->merchant);

    expect($settlement)->not->toBeNull()
        ->and($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->lines()->count())->toBe(4)
        ->and($settlement->discount_laari)->toBe(162)
        ->and($settlement->discount_reason)->toBe('eligible')
        ->and($settlement->discount_posted_laari)->toBe(162)
        ->and($settlement->amount_due_laari)->toBe(11_663)
        // What actually left the wallet is what submit() priced, never what
        // the plan estimated.
        ->and($settlement->amount_received_laari)->toBe(11_663)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(11_700 - 11_663)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225 - 162)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    foreach ($fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed);
    }

    // Nothing is left owing, so there is nothing for the next hour to find.
    expect(app(SettlementBuilder::class)->eligibleTransactions($fixture->merchant)->count())->toBe(0);

    expect(autoSettleSmsBodies()[0])
        ->toContain('MVR 116.63')
        ->toContain('4 sales')
        ->toContain('MVR 0.37');
});

it('a balance short of even the DISCOUNTED total still settles a prefix, which earns no discount', function (): void {
    $fixture = PromptFixture::fourLines();
    // Below 11,663: no arrangement of the board is affordable whole, so the
    // run takes the three lines it can pay for outright (9,625) and leaves
    // the fourth — which withdraws the discount, because the batch no longer
    // clears everything outstanding.
    autoSettleTopUp($fixture->merchant, 9_700);

    $settlement = app(WalletAutoSettler::class)->settle($fixture->merchant);

    expect($settlement)->not->toBeNull()
        ->and($settlement->lines()->count())->toBe(3)
        ->and($settlement->discount_laari)->toBe(0)
        ->and($settlement->discount_reason)->toBe('not_all_outstanding')
        ->and($settlement->amount_received_laari)->toBe(9_625)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(9_700 - 9_625)
        ->and($fixture->transactions[3]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('settles everything on EXACTLY the discounted total', function (): void {
    $fixture = PromptFixture::fourLines();
    autoSettleTopUp($fixture->merchant, 11_663);

    $settlement = app(WalletAutoSettler::class)->settle($fixture->merchant);

    // The boundary itself: the board clears and the wallet empties.
    expect($settlement)->not->toBeNull()
        ->and($settlement->lines()->count())->toBe(4)
        ->and($settlement->discount_laari)->toBe(162)
        ->and($settlement->discount_reason)->toBe('eligible')
        ->and($settlement->amount_received_laari)->toBe(11_663)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('drops to a prefix ONE LAARI below the discounted total', function (): void {
    $fixture = PromptFixture::fourLines();
    autoSettleTopUp($fixture->merchant, 11_662);

    $settlement = app(WalletAutoSettler::class)->settle($fixture->merchant);

    // One laari short of the whole board's price: the fourth line waits, and
    // with it the discount.
    expect($settlement)->not->toBeNull()
        ->and($settlement->lines()->count())->toBe(3)
        ->and($settlement->discount_laari)->toBe(0)
        ->and($settlement->discount_reason)->toBe('not_all_outstanding')
        ->and($settlement->amount_received_laari)->toBe(9_625)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(11_662 - 9_625)
        ->and($fixture->transactions[3]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('settles everything on EXACTLY the undiscounted total and keeps the relief in the wallet', function (): void {
    $fixture = PromptFixture::fourLines();
    // The old planner's happy path: affordable line by line, so the whole
    // board is chosen without the discount ever being evaluated — and submit
    // still grants it, so the 162 stays in the wallet.
    autoSettleTopUp($fixture->merchant, 11_825);

    $settlement = app(WalletAutoSettler::class)->settle($fixture->merchant);

    expect($settlement)->not->toBeNull()
        ->and($settlement->lines()->count())->toBe(4)
        ->and($settlement->discount_laari)->toBe(162)
        ->and($settlement->amount_received_laari)->toBe(11_663)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(162)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('never overdraws when the discount is withdrawn between the plan and the lock', function (): void {
    $fixture = PromptFixture::fourLines();
    // In the band: the plan will choose all four lines at 11,663.
    autoSettleTopUp($fixture->merchant, 11_700);

    // ...and a till POSTs one more payable sale in the gap, so the batch no
    // longer clears the board and submit() prices it at the full 11,825.
    $planned = app(WalletAutoSettler::class)->plan($fixture->merchant, 11_700);

    expect($planned)->toHaveCount(4);

    $fixture->addPayable(100_000, CarbonImmutable::parse(PromptFixture::BASE)->addDays(3)->addHour());

    expect(fn () => app(SettlementBuilder::class)->createAndSettleFromWallet($fixture->merchant, Actor::system(), $planned))
        ->toThrow(InsufficientWalletBalanceException::class);

    // Nothing half-done: no batch, no debit, every line still payable.
    expect(Settlement::query()->count())->toBe(0)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(11_700);

    foreach ($fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded);
    }

    // And the run itself survives it — one skipped shop, money untouched.
    expect(app(WalletAutoSettler::class)->run())->toBe(['checked' => 1, 'settled' => 1, 'skipped' => 0])
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('a second run is a no-op', function (): void {
    $fixture = SettlementFixture::payableBatch();
    autoSettleTopUp($fixture->merchant, 20_000);

    $settler = app(WalletAutoSettler::class);

    expect($settler->run())->toBe(['checked' => 1, 'settled' => 1, 'skipped' => 0])
        ->and($settler->run())->toBe(['checked' => 0, 'settled' => 0, 'skipped' => 0])
        ->and($settler->settle($fixture->merchant))->toBeNull()
        ->and(Settlement::query()->count())->toBe(1)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(8_175);

    Queue::assertPushed(SendCustomerSms::class, 1);
});

it('one store\'s failure never stops the run', function (): void {
    $blocked = SettlementFixture::payableBatch('111111');
    $fine = SettlementFixture::payableBatch('222222');
    autoSettleTopUp($blocked->merchant, 20_000);
    autoSettleTopUp($fine->merchant, 20_000);

    // The documented race, staged: a cancelled batch that still holds a
    // line row (cancel() deletes them; this one did not get to). The
    // eligibility query looks past cancelled batches, so the plan picks the
    // line — and the unique settlement_lines.transaction_id index refuses
    // the snapshot, exactly as it refuses the loser of two concurrent
    // drafts. NotEligibleForSettlementException, rolled back, logged.
    $ghost = Settlement::query()->create([
        'merchant_id' => $blocked->merchant->id,
        'reference' => 'ST-2026-99999',
        'state' => SettlementState::Cancelled,
        'funding_method' => 'bank',
        'currency' => 'MVR',
    ]);
    $ghost->lines()->create([
        'transaction_id' => $blocked->transactions[0]->id,
        'cashback_laari' => $blocked->transactions[0]->cashback_laari,
        'fee_laari' => $blocked->transactions[0]->fee_laari,
        'fee_gst_laari' => $blocked->transactions[0]->fee_gst_laari,
        'currency' => 'MVR',
    ]);

    $run = app(WalletAutoSettler::class)->run();

    expect($run)->toBe(['checked' => 2, 'settled' => 1, 'skipped' => 1])
        // The blocked store: nothing moved, nothing half-done.
        ->and(Settlement::query()->where('merchant_id', $blocked->merchant->id)->where('state', '!=', SettlementState::Cancelled->value)->count())->toBe(0)
        ->and($blocked->merchant->wallet()->sole()->balance_laari)->toBe(20_000)
        ->and($blocked->transactions[1]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        // The next store settled as if nothing had happened.
        ->and(Settlement::query()->where('merchant_id', $fine->merchant->id)->sole()->state)->toBe(SettlementState::Settled)
        ->and($fine->merchant->wallet()->sole()->balance_laari)->toBe(8_175);

    Queue::assertPushed(SendCustomerSms::class, 1);
});

it('runs from the console command', function (): void {
    $fixture = SettlementFixture::payableBatch();
    autoSettleTopUp($fixture->merchant, 20_000);

    $this->artisan('manfaa:auto-settle-wallets')
        ->expectsOutputToContain('1 merchant(s) checked, 1 settled from wallet, 0 skipped.')
        ->assertSuccessful();

    expect(Settlement::query()->sole()->state)->toBe(SettlementState::Settled);
});

it('is scheduled ten minutes past every hour, behind the validation sweep', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'manfaa:auto-settle-wallets'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('10 * * * *');
});

it('says nothing about the wallet when §7 credits net the batch to zero and the wallet paid nothing', function (): void {
    $fixture = SettlementFixture::payableBatch();
    $builder = app(SettlementBuilder::class);
    $allocator = app(SettlementAllocator::class);

    // Everything on the board settles by bank; T0 (due 2750) is then
    // refunded, leaving a pending 2750 credit for the next batch.
    $batch = $builder->createDraft($fixture->merchant);
    $builder->submit($batch);
    $payment = $allocator->recordBankPayment($batch->refresh(), Laari::of((int) $batch->amount_due_laari), 'BML-ALL');
    $allocator->matchPayment($payment, AdminUser::factory()->create());

    $t0 = $fixture->transactions[0];
    $outcome = app(ReversalService::class)->reverse($t0, Actor::system(), 'customer_refund', now()->toImmutable());
    expect($outcome->outcome)->toBe('adjustment_created');

    // A fresh sale with the SAME due (100000 @ 200bp/75bp → 2750).
    $fresh = app(ManualCreditService::class)->credit(
        $fixture->merchant,
        $fixture->user,
        $fixture->customer->customer_code,
        'INV-NET-0',
        Laari::of(100000),
        null,
        now()->subHour()->toImmutable(),
    );
    app(TransitionService::class)->makePayable($fresh, Actor::system());

    autoSettleTopUp($fixture->merchant, 5_000);

    $run = app(WalletAutoSettler::class)->run();

    // Settled — inside submit, by the credit — and the wallet untouched.
    $settlement = Settlement::query()->orderByDesc('id')->firstOrFail();

    expect($run['settled'])->toBe(1)
        ->and($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_due_laari)->toBe(0)
        ->and($settlement->funding_method)->toBe('bank')
        ->and($settlement->amount_received_laari)->toBe(0)
        ->and($fresh->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($fixture->merchant->wallet()->sole()->balance_laari)->toBe(5_000);

    // No "MVR 0.00 was settled from your wallet" (the customer's own
    // credit SMS is the only message queued).
    foreach (autoSettleSmsBodies() as $body) {
        expect($body)->not->toContain('from your wallet');
    }
});
