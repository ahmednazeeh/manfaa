<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\DuplicateInvoiceException;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
});

/**
 * The Phase 0 exit criterion (§12): a few hundred manual credits, advanced
 * through the state machine with real ledger postings, must reconcile to the
 * laari — with every expectation derived from the transactions table, never
 * from the ledger it is checking.
 */
it('reconciles a few hundred seeded transactions to the laari', function () {
    mt_srand(20260814);

    $now = CarbonImmutable::now('UTC');
    $rateBoundary = $now->subHours(72);

    // Five merchants across the tier table: 50, 137, 200, 499 standing, and
    // one whose history crosses the 499 → 500 tier cliff mid-period.
    $merchants = [];

    foreach ([50, 137, 200, 499] as $rateBp) {
        $merchant = Merchant::factory()->create();
        MerchantRate::factory()->for($merchant)->create([
            'rate_bp' => $rateBp,
            'effective_from' => $now->subYear(),
            'effective_to' => null,
        ]);
        $merchants[] = $merchant;
    }

    $cliffMerchant = Merchant::factory()->create();
    MerchantRate::factory()->for($cliffMerchant)->create([
        'rate_bp' => 499,
        'effective_from' => $now->subYear(),
        'effective_to' => $rateBoundary,
    ]);
    MerchantRate::factory()->for($cliffMerchant)->create([
        'rate_bp' => 500,
        'effective_from' => $rateBoundary,
        'effective_to' => null,
    ]);
    $merchants[] = $cliffMerchant;

    $standingRates = [50, 137, 200, 499, null]; // null = resolve against the cliff boundary
    $feeTier = [50 => 25, 137 => 50, 200 => 75, 499 => 75, 500 => 100];

    $users = [];
    foreach ($merchants as $merchant) {
        $users[$merchant->id] = MerchantUser::factory()->for($merchant)->owner()->create();
    }

    $customers = Customer::factory()->count(20)->create()->all();

    $service = app(ManualCreditService::class);
    $transitions = app(TransitionService::class);
    $postings = app(Postings::class);
    $balances = new Balances;

    // Awkward primes scattered through the 5000..5000000 laari range.
    $primes = [5003, 7919, 104729, 611953, 999983, 1299709, 2750159];

    $expectedEvents = [];   // transaction id => event rows this test earned it
    $created = [];          // [merchant, invoice_no] pairs for duplicate replays
    $belowMinimumCount = 0;

    for ($i = 1; $i <= 295; $i++) {
        $merchantIndex = mt_rand(0, 4);
        $merchant = $merchants[$merchantIndex];
        $customer = $customers[mt_rand(0, 19)];
        $occurredAt = $now->subMinutes(mt_rand(60, 8500));
        $belowMinimum = $i % 49 === 0;

        $eligible = match (true) {
            $belowMinimum => mt_rand(1000, 4999),
            $i % 24 === 0 => $primes[($i / 24) % count($primes)],
            default => mt_rand(5000, 5000000),
        };

        $invoiceNo = sprintf('INV-%d-%04d', $merchant->id, $i);

        $transaction = $service->credit(
            $merchant,
            $users[$merchant->id],
            $customer->customer_code,
            $invoiceNo,
            Laari::of($eligible),
            null,
            $occurredAt,
        );

        // Independent per-line derivation: the rate the history should have
        // resolved and the §4 integers, straight from the plan's formula.
        $rateBp = $standingRates[$merchantIndex] ?? ($occurredAt->lt($rateBoundary) ? 499 : 500);
        $feeBp = $feeTier[$rateBp];

        expect($transaction->rate_bp)->toBe($rateBp)
            ->and($transaction->fee_bp)->toBe($feeBp)
            ->and($transaction->cashback_laari)->toBe($belowMinimum ? 0 : intdiv($eligible * $rateBp + 5000, 10000))
            ->and($transaction->fee_laari)->toBe($belowMinimum ? 0 : intdiv($eligible * $feeBp + 5000, 10000))
            ->and($transaction->fee_gst_laari)->toBe(0)
            ->and($transaction->state)->toBe(
                $belowMinimum ? TransactionState::Tracked : TransactionState::AwaitingValidation
            );

        $expectedEvents[$transaction->id] = $belowMinimum ? 1 : 2;
        $belowMinimumCount += $belowMinimum ? 1 : 0;
        $created[] = [$merchant, $invoiceNo];
    }

    expect($belowMinimumCount)->toBe(6);

    // A few duplicate invoice replays — each must throw and leave nothing behind.
    $journalsBeforeDuplicates = DB::table('ledger_journals')->count();
    $caughtDuplicates = 0;

    for ($d = 0; $d < 5; $d++) {
        [$merchant, $invoiceNo] = $created[mt_rand(0, count($created) - 1)];

        try {
            $service->credit(
                $merchant,
                $users[$merchant->id],
                $customers[mt_rand(0, 19)]->customer_code,
                $invoiceNo,
                Laari::of(mt_rand(5000, 5000000)),
                null,
                $now->subMinutes(mt_rand(60, 8500)),
            );
        } catch (DuplicateInvoiceException) {
            $caughtDuplicates++;
        }
    }

    expect($caughtDuplicates)->toBe(5)
        ->and(Transaction::query()->count())->toBe(295)
        ->and(DB::table('ledger_journals')->count())->toBe($journalsBeforeDuplicates);

    // Advance ~60% of the accrued transactions onto the settlement clock.
    $payable = [];

    foreach (Transaction::query()->where('state', 'awaiting_validation')->orderBy('id')->get() as $transaction) {
        if (mt_rand(1, 100) <= 60) {
            $transitions->makePayable($transaction, Actor::system());
            $expectedEvents[$transaction->id]++;
            $payable[] = $transaction;
        }
    }

    // Of those: ~30% settle and confirm (§8 — settlement posts, confirmation
    // does not), ~10% reverse. Both post the EXACT stored row integers.
    $confirmedCount = 0;
    $reversedCount = 0;

    foreach ($payable as $transaction) {
        $roll = mt_rand(1, 100);

        if ($roll <= 30) {
            $postings->bankSettlementReceived(
                $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari,
                'transaction',
                $transaction->id,
            );
            $transitions->confirm($transaction, Actor::system());
            $expectedEvents[$transaction->id]++;
            $confirmedCount++;
        } elseif ($roll <= 40) {
            $postings->reverseAccrual(
                $transaction->cashback_laari,
                $transaction->fee_laari,
                $transaction->fee_gst_laari,
                referenceId: $transaction->id,
            );
            $transitions->reverse($transaction, Actor::system(), 'customer_refund');
            $expectedEvents[$transaction->id]++;
            $reversedCount++;
        }
    }

    expect($confirmedCount)->toBeGreaterThan(0)
        ->and($reversedCount)->toBeGreaterThan(0)
        ->and(Transaction::query()->where('state', 'confirmed')->count())->toBe($confirmedCount)
        ->and(Transaction::query()->where('state', 'reversed')->count())->toBe($reversedCount);

    // Exactly one journal per accrual, settlement, and reversal — no strays.
    expect(DB::table('ledger_journals')->count())->toBe((295 - 6) + $confirmedCount + $reversedCount);

    // (a) Every journal balances, checked by SQL GROUP BY … HAVING.
    $unbalanced = DB::table('ledger_entries')
        ->select('journal_id')
        ->groupBy('journal_id', 'currency')
        ->havingRaw('SUM(debit_laari) <> SUM(credit_laari)')
        ->get();

    expect($unbalanced)->toHaveCount(0)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and((int) DB::table('ledger_entries')->sum(DB::raw('debit_laari - credit_laari')))->toBe(0);

    // (b)–(d) Expected balances derived from the transactions table alone.
    $accrued = DB::table('transactions')
        ->where('state', '!=', 'reversed')
        ->selectRaw(<<<'SQL'
            COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari), 0) AS total_laari,
            COALESCE(SUM(cashback_laari), 0) AS cashback_laari,
            COALESCE(SUM(fee_laari), 0) AS fee_laari
            SQL)
        ->first();

    $settledLaari = (int) DB::table('transactions')
        ->where('state', 'confirmed')
        ->sum(DB::raw('cashback_laari + fee_laari + fee_gst_laari'));

    $paidLaari = 0; // no payout ran in this scenario

    expect($balances->accountBalance(AccountCode::MerchantReceivable))
        ->toBe((int) $accrued->total_laari - $settledLaari)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))
        ->toBe((int) $accrued->cashback_laari - $paidLaari)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))
        ->toBe((int) $accrued->fee_laari)
        ->and($balances->naturalBalance(AccountCode::SettlementCash))
        ->toBe($settledLaari);

    // (f) Event-log completeness: every transaction has exactly one creation
    // event and one row per transition after it — nothing moved silently.
    $actualEvents = DB::table('transaction_events')
        ->selectRaw(<<<'SQL'
            transaction_id,
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE from_state IS NULL AND to_state = 'tracked') AS creations,
            COUNT(*) FILTER (WHERE from_state IS NOT NULL) AS transitions
            SQL)
        ->groupBy('transaction_id')
        ->get()
        ->keyBy('transaction_id');

    expect($actualEvents)->toHaveCount(295);

    $actualTotals = [];
    foreach ($actualEvents as $id => $row) {
        expect((int) $row->creations)->toBe(1)
            ->and((int) $row->total)->toBe((int) $row->transitions + 1);
        $actualTotals[$id] = (int) $row->total;
    }

    ksort($expectedEvents);
    ksort($actualTotals);
    expect($actualTotals)->toBe($expectedEvents);

    fwrite(STDERR, sprintf(
        "\nReconciliation: %d transactions (%d below-minimum, %d duplicates rejected) | payable %d, confirmed %d, reversed %d | accrued non-reversed: cashback %d + fee %d = %d laari | settled %d laari | receivable %d laari — reconciled to the laari\n",
        295,
        $belowMinimumCount,
        $caughtDuplicates,
        count($payable),
        $confirmedCount,
        $reversedCount,
        (int) $accrued->cashback_laari,
        (int) $accrued->fee_laari,
        (int) $accrued->total_laari,
        $settledLaari,
        (int) $accrued->total_laari - $settledLaari,
    ));
});

/**
 * The §4 fixture, verbatim: four invoices at 200bp / 75bp, per-line rounding,
 * batch totals as the sum of rounded lines.
 */
it('reproduces the §4 fixture batch to the laari', function () {
    $now = CarbonImmutable::now('UTC');

    $merchant = Merchant::factory()->create();
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => $now->subYear(),
        'effective_to' => null,
    ]);
    $user = MerchantUser::factory()->for($merchant)->owner()->create();
    $customer = Customer::factory()->create();

    $service = app(ManualCreditService::class);
    $balances = new Balances;

    $fixture = [
        ['INV-1001', 100_000, 2_000, 750, 2_750],
        ['INV-1002', 50_000, 1_000, 375, 1_375],
        ['INV-1003', 200_000, 4_000, 1_500, 5_500],
        ['INV-1004', 80_000, 1_600, 600, 2_200],
    ];

    foreach ($fixture as [$invoiceNo, $eligible, $cashback, $fee, $due]) {
        $transaction = $service->credit(
            $merchant,
            $user,
            $customer->customer_code,
            $invoiceNo,
            Laari::of($eligible),
            null,
            $now->subHours(2),
        );

        expect($transaction->rate_bp)->toBe(200)
            ->and($transaction->fee_bp)->toBe(75)
            ->and($transaction->cashback_laari)->toBe($cashback)
            ->and($transaction->fee_laari)->toBe($fee)
            ->and($transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari)->toBe($due);
    }

    $batch = DB::table('transactions')
        ->selectRaw(<<<'SQL'
            SUM(eligible_laari) AS eligible_laari,
            SUM(cashback_laari) AS cashback_laari,
            SUM(fee_laari) AS fee_laari,
            SUM(cashback_laari + fee_laari + fee_gst_laari) AS due_laari
            SQL)
        ->first();

    expect((int) $batch->eligible_laari)->toBe(430_000)
        ->and((int) $batch->cashback_laari)->toBe(8_600)
        ->and((int) $batch->fee_laari)->toBe(3_225)
        ->and((int) $batch->due_laari)->toBe(11_825);

    // = MVR 4,300.00 / 86.00 / 32.25 / 118.25.
    expect(Laari::of(430_000)->formatMvr())->toBe('4,300.00')
        ->and(Laari::of(8_600)->formatMvr())->toBe('86.00')
        ->and(Laari::of(3_225)->formatMvr())->toBe('32.25')
        ->and(Laari::of(11_825)->formatMvr())->toBe('118.25');

    // The batch accrues to the ledger as the sum of rounded lines.
    expect($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(11_825)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(8_600)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(3_225);
});
