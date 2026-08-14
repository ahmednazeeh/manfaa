<?php

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A payable_unfunded transaction with its accrual posted from the SAME stored
 * integers the write-off must later reverse — including a deliberate gst
 * component so all three write-off legs are exercised.
 */
function payableWithAccrual(Merchant $merchant, CarbonImmutable $clockStart, int $cashback, int $fee, int $gst): Transaction
{
    $transaction = Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
        'cashback_laari' => $cashback,
        'fee_laari' => $fee,
        'fee_gst_laari' => $gst,
    ]);

    app(Postings::class)->accrue($cashback, $fee, $gst, referenceId: $transaction->id);

    return $transaction;
}

it('writes off unfunded payables more than 90 days past due with a balanced journal from the stored integers', function () {
    $clockStart = CarbonImmutable::parse('2026-05-01T09:00:00+05:00')->utc();
    Carbon::setTestNow($clockStart);

    $merchant = Merchant::factory()->create();
    $old = payableWithAccrual($merchant, $clockStart, 2_000, 750, 45);
    $older = payableWithAccrual($merchant, $clockStart, 1_000, 375, 23);

    // A younger debt on the same merchant, inside its 90 days — untouched.
    $young = payableWithAccrual($merchant, $clockStart->addDays(60), 4_000, 1_500, 90);

    $balances = app(Balances::class);
    $receivableBefore = $balances->accountBalance(AccountCode::MerchantReceivable);
    expect($receivableBefore)->toBe(2_795 + 1_398 + 5_590);

    // due_at + 89 days: nothing fires yet.
    Carbon::setTestNow($clockStart->addDays(15)->addDays(89));
    $this->artisan('manfaa:write-off')->assertExitCode(0);
    expect($old->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // due_at + 90 days and an hour: the two old debts are written off.
    Carbon::setTestNow($clockStart->addDays(15)->addDays(90)->addHour());
    $this->artisan('manfaa:write-off')->assertExitCode(0);

    expect($old->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and($old->reason_code)->toBe('merchant_default_90d')
        ->and($older->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and($young->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // Each write-off wrote exactly one event, actor system, reason recorded.
    foreach ([$old, $older] as $transaction) {
        $events = $transaction->events()->where('to_state', 'written_off')->get();
        expect($events)->toHaveCount(1)
            ->and($events->first()->actor_type)->toBe('system')
            ->and($events->first()->reason_code)->toBe('merchant_default_90d');
    }

    // The §8 posting, per transaction: DR Bad Debt (fee+gst), DR Liability
    // (cashback), CR Receivable (total) — each journal individually balanced.
    $journals = DB::table('ledger_journals')
        ->where('description', 'Unsettled reward written off')
        ->orderBy('reference_id')
        ->get();

    expect($journals)->toHaveCount(2)
        ->and($journals->pluck('reference_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([$old->id, $older->id]);

    foreach ($journals as $journal) {
        $sums = DB::table('ledger_entries')
            ->where('journal_id', $journal->id)
            ->selectRaw('SUM(debit_laari) AS debits, SUM(credit_laari) AS credits')
            ->first();
        expect((int) $sums->debits)->toBe((int) $sums->credits);
    }

    // Receivable falls by EXACTLY the stored totals of the written-off rows;
    // the young debt's accrual stays put.
    expect($balances->accountBalance(AccountCode::MerchantReceivable))
        ->toBe($receivableBefore - 2_795 - 1_398)
        ->toBe(5_590);
    expect($balances->naturalBalance(AccountCode::BadDebtExpense))->toBe(750 + 45 + 375 + 23)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(4_000);

    // One write_off notice for the merchant, summing the stored integers.
    $notice = MerchantNotice::query()->where('type', 'write_off')->sole();
    expect($notice->merchant_id)->toBe($merchant->id)
        ->and($notice->payload['written_off_transactions'])->toBe(2)
        ->and($notice->payload['written_off_laari'])->toBe(2_795 + 1_398)
        ->and($notice->payload['reason_code'])->toBe('merchant_default_90d');
});

it('never writes off a transaction frozen on a live settlement — its late payment still matches', function () {
    $clockStart = CarbonImmutable::parse('2026-05-01T09:00:00+05:00')->utc();
    Carbon::setTestNow($clockStart);

    $merchant = Merchant::factory()->create();
    $onBatch = payableWithAccrual($merchant, $clockStart, 2_000, 750, 0);
    $loose = payableWithAccrual($merchant, $clockStart, 1_000, 375, 0);

    // The merchant submitted a batch holding one of the debts, then went
    // silent — the batch sits awaiting_payment past the 90-day horizon.
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->createDraft($merchant, [$onBatch->id]);
    $builder->submit($settlement);

    Carbon::setTestNow($clockStart->addDays(15)->addDays(91));
    $this->artisan('manfaa:write-off')->assertExitCode(0);

    // The loose debt wrote off; the one frozen on the live batch did NOT —
    // written_off is terminal, and allocation must still be able to confirm
    // the line when the merchant finally pays.
    expect($loose->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and($onBatch->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // The late payment arrives and matches cleanly: line confirmed, cash
    // booked, no InvalidTransitionException, no stranded money.
    $allocator = app(SettlementAllocator::class);
    $payment = $allocator->recordBankPayment($settlement->refresh(), Laari::of(2_750), 'BML-LATE-2750');
    $settlement = $allocator->matchPayment($payment, AdminUser::factory()->create());

    $balances = app(Balances::class);

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($onBatch->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($balances->journalsAllBalance())->toBeTrue();
});

it('writes off a batched transaction once its settlement is cancelled', function () {
    $clockStart = CarbonImmutable::parse('2026-05-01T09:00:00+05:00')->utc();
    Carbon::setTestNow($clockStart);

    $merchant = Merchant::factory()->create();
    $transaction = payableWithAccrual($merchant, $clockStart, 2_000, 750, 0);

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->createDraft($merchant, [$transaction->id]);
    $builder->submit($settlement);

    Carbon::setTestNow($clockStart->addDays(15)->addDays(91));
    $this->artisan('manfaa:write-off')->assertExitCode(0);
    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // Cancelling the stale, unpaid batch releases its claim — the next sweep
    // picks the debt up.
    $builder->cancel($settlement);
    $this->artisan('manfaa:write-off')->assertExitCode(0);

    expect($transaction->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and(app(Balances::class)->accountBalance(AccountCode::MerchantReceivable))->toBe(0);
});

it('is idempotent — a re-run moves nothing, posts nothing, notices nothing', function () {
    $clockStart = CarbonImmutable::parse('2026-05-01T09:00:00+05:00')->utc();
    Carbon::setTestNow($clockStart);

    $merchant = Merchant::factory()->create();
    $transaction = payableWithAccrual($merchant, $clockStart, 2_000, 750, 0);

    Carbon::setTestNow($clockStart->addDays(15)->addDays(91));
    $this->artisan('manfaa:write-off')->assertExitCode(0);
    $this->artisan('manfaa:write-off')->assertExitCode(0);

    expect($transaction->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and($transaction->events()->where('to_state', 'written_off')->count())->toBe(1)
        ->and(DB::table('ledger_journals')->where('description', 'Unsettled reward written off')->count())->toBe(1)
        ->and(MerchantNotice::query()->where('type', 'write_off')->count())->toBe(1);

    expect(app(Balances::class)->accountBalance(AccountCode::MerchantReceivable))->toBe(0);
});
