<?php

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\Laari;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\ReconciliationRun;
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
 * Real activity through the real path: two manual credits whose accruals hit
 * the ledger exactly as production does.
 */
function creditSomeActivity(): void
{
    $now = CarbonImmutable::now('UTC');

    $merchant = Merchant::factory()->create();
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => $now->subYear(),
        'effective_to' => null,
    ]);
    $user = MerchantUser::factory()->for($merchant)->create();
    $customer = Customer::factory()->create();

    $credits = app(ManualCreditService::class);
    $credits->credit($merchant, $user, $customer->customer_code, 'INV-RC-1', Laari::of(100_000), null, $now->subHour());
    $credits->credit($merchant, $user, $customer->customer_code, 'INV-RC-2', Laari::of(50_000), null, $now->subHour());
}

it('records an ok run when every journal balances and derived totals match the ledger', function () {
    creditSomeActivity();

    $this->artisan('manfaa:reconcile')->assertExitCode(0);

    $run = ReconciliationRun::query()->sole();

    expect($run->status)->toBe('ok')
        ->and($run->journals_checked)->toBe(2)
        ->and($run->issues)->toBeNull();

    // 100000 and 50000 eligible @200bp/75bp, ceiling per line, derived from
    // the transactions table and matching the ledger to the laari.
    expect($run->totals['receivable']['derived_laari'])->toBe(2_750 + 1_375)
        ->and($run->totals['receivable']['derived_laari'])->toBe($run->totals['receivable']['ledger_laari'])
        ->and($run->totals['liability']['derived_laari'])->toBe(2_000 + 1_000)
        ->and($run->totals['liability']['derived_laari'])->toBe($run->totals['liability']['ledger_laari'])
        ->and($run->totals['revenue']['derived_laari'])->toBe(750 + 375)
        ->and($run->totals['revenue']['derived_laari'])->toBe($run->totals['revenue']['ledger_laari']);
});

it('records a divergent run and exits 1 when a journal does not balance', function () {
    creditSomeActivity();

    // A raw, unbalanced insert — exactly the corruption the §5 invariant
    // exists to catch. Settlement Cash keeps it out of the derived totals so
    // the issue list isolates the journal check.
    $journalId = DB::table('ledger_journals')->insertGetId([
        'reference_type' => 'corruption',
        'reference_id' => 0,
        'description' => 'Raw unbalanced insert',
        'posted_at' => CarbonImmutable::now('UTC'),
        'created_at' => CarbonImmutable::now('UTC'),
    ]);

    $cashAccountId = (int) DB::table('ledger_accounts')->where('code', '1000')->value('id');

    DB::table('ledger_entries')->insert([
        'journal_id' => $journalId,
        'account_id' => $cashAccountId,
        'debit_laari' => 999,
        'credit_laari' => 0,
        'currency' => 'MVR',
        'created_at' => CarbonImmutable::now('UTC'),
    ]);

    $this->artisan('manfaa:reconcile')->assertExitCode(1);

    $run = ReconciliationRun::query()->latest('id')->sole();

    expect($run->status)->toBe('divergent')
        ->and($run->journals_checked)->toBe(3)
        ->and($run->issues)->toHaveCount(1)
        ->and($run->issues[0]['kind'])->toBe('unbalanced_journal')
        ->and($run->issues[0]['journal_id'])->toBe($journalId)
        ->and($run->issues[0]['debit_laari'])->toBe(999)
        ->and($run->issues[0]['credit_laari'])->toBe(0);
});

it('flags a derived-versus-ledger mismatch as divergent', function () {
    creditSomeActivity();

    // Balanced but wrong: a journal that moves value out of the receivable
    // with no transaction-side event backing it. The journal check stays
    // green; the derived comparison must not.
    $journalId = DB::table('ledger_journals')->insertGetId([
        'reference_type' => 'corruption',
        'reference_id' => 0,
        'description' => 'Phantom receivable clearance',
        'posted_at' => CarbonImmutable::now('UTC'),
        'created_at' => CarbonImmutable::now('UTC'),
    ]);

    $accounts = DB::table('ledger_accounts')->pluck('id', 'code');

    DB::table('ledger_entries')->insert([
        [
            'journal_id' => $journalId,
            'account_id' => (int) $accounts['1000'],
            'debit_laari' => 500,
            'credit_laari' => 0,
            'currency' => 'MVR',
            'created_at' => CarbonImmutable::now('UTC'),
        ],
        [
            'journal_id' => $journalId,
            'account_id' => (int) $accounts['1100'],
            'debit_laari' => 0,
            'credit_laari' => 500,
            'currency' => 'MVR',
            'created_at' => CarbonImmutable::now('UTC'),
        ],
    ]);

    $this->artisan('manfaa:reconcile')->assertExitCode(1);

    $run = ReconciliationRun::query()->latest('id')->sole();

    expect($run->status)->toBe('divergent')
        ->and(collect($run->issues)->pluck('kind')->all())->toBe(['balance_mismatch'])
        ->and($run->issues[0]['account'])->toBe('receivable')
        ->and($run->issues[0]['derived_laari'])->toBe(2_750 + 1_375)
        ->and($run->issues[0]['ledger_laari'])->toBe(2_750 + 1_375 - 500);
});
