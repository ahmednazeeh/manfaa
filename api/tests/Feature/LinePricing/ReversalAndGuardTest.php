<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    Customer::factory()->create(['customer_code' => '482917']);

    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'mode' => 'rate', 'rate_bp' => 200, 'active' => true, 'sort' => 2,
    ]);

    $this->actingAs($this->user, 'merchant');
});

/**
 * Books the §4-fixture mixed basket (2,750 cashback / 638 fee) and returns
 * the transaction.
 */
function lpReversalBasket(): Transaction
{
    test()->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-REV-1',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => 'veggies', 'amount_laari' => 25000],
            ['category' => null, 'amount_laari' => 45000],
        ],
    ])->assertCreated();

    return Transaction::query()->sole();
}

it('reverses a lined transaction by its STORED totals — ledger nets zero, line snapshots untouched', function () {
    $transaction = lpReversalBasket();

    expect($transaction->cashback_laari)->toBe(2750)
        ->and($transaction->fee_laari)->toBe(638);

    $linesBefore = TransactionLine::query()->where('transaction_id', $transaction->id)
        ->orderBy('sort')->get()->map(fn (TransactionLine $l) => $l->getAttributes())->all();

    // Move every category's terms AFTER the sale — the reversal must mirror
    // the STORED integers, never re-price against the new terms.
    MerchantProductCategory::query()->where('slug', 'veggies')->first()->update(['rate_bp' => 900]);
    MerchantProductCategory::query()->where('slug', 'fruits')->first()->update(['mode' => 'rate', 'rate_bp' => 300]);

    $outcome = app(ReversalService::class)->reverse(
        $transaction,
        Actor::system(),
        'customer_refund',
        CarbonImmutable::now('UTC'),
    );

    expect($outcome->transaction->state)->toBe(TransactionState::Reversed);

    // Row totals unchanged (stored snapshot), lines byte-identical.
    $reversed = Transaction::query()->sole();
    $linesAfter = TransactionLine::query()->where('transaction_id', $transaction->id)
        ->orderBy('sort')->get()->map(fn (TransactionLine $l) => $l->getAttributes())->all();

    expect($reversed->cashback_laari)->toBe(2750)
        ->and($reversed->fee_laari)->toBe(638)
        ->and($linesAfter)->toBe($linesBefore);

    // Accrual + mirror = every account nets to zero; both journals balance.
    $balances = new Balances;

    expect(DB::table('ledger_journals')->count())->toBe(2)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(0)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(0);
});

it('refuses updates and deletes on transaction lines — append-only like ledger entries', function () {
    lpReversalBasket();

    $line = TransactionLine::query()->orderBy('id')->first();

    expect(fn () => $line->update(['cashback_laari' => 999999]))
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => $line->fresh()->delete())
        ->toThrow(LogicException::class, 'append-only');

    // Nothing moved.
    expect(TransactionLine::query()->count())->toBe(3)
        ->and(TransactionLine::query()->orderBy('id')->first()->cashback_laari)->toBe(0);
});
