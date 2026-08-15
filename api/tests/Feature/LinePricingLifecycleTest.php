<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\ReconciliationRun;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ONE end-to-end HTTP journey for line-item pricing (Task #25, PLAN §1
 * product-category rates + §4 money law) — every step a real request
 * through the real routes, every integer hand-derived in the comments:
 *
 *   owner creates the categories over HTTP (excluded Fruits, 2% Veggies)
 *   → STAFF credits a mixed basket with lines
 *   → owner drafts + publishes a capped 6% promotion over HTTP
 *   → staff credits a second mixed basket: the promo lifts veggies AND the
 *     default bucket, never the excluded line, and the per-customer cap
 *     clips ACROSS lines in submitted order with fee-follows-granted
 *   → the validation sweep puts both on the settlement clock
 *   → owner builds + submits the settlement, admin records + matches the
 *     bank payment, both transactions confirm
 *   → the Reconciler runs clean, and the totals derived from the STORED
 *     line integers equal the transaction rows and the ledger exactly.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->base = CarbonImmutable::parse('2026-08-01T10:00:00+00:00');
    Carbon::setTestNow($this->base);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500, // standing 5% → §4 fee tier 100bp
        'effective_from' => $this->base->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->staff = MerchantUser::factory()->for($this->merchant)->create(); // role: staff
    $this->admin = AdminUser::factory()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('walks the full lined-credit lifecycle over HTTP: categories → credits → promo → cap → settlement → reconciliation', function () {
    // ── 1. OWNER creates the product categories over HTTP ────────────────
    $this->actingAs($this->owner, 'merchant');

    $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Fruits', 'mode' => 'excluded', 'sort' => 1,
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'fruits')
        ->assertJsonPath('data.mode', 'excluded')
        ->assertJsonPath('data.rate_bp', null);

    $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Veggies', 'mode' => 'rate', 'rate_bp' => 200, 'sort' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'veggies')
        ->assertJsonPath('data.rate_bp', 200);

    // Staff read the list — it feeds the credit form.
    $this->actingAs($this->staff, 'merchant');
    $this->getJson('/api/merchant/product-categories')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'fruits')
        ->assertJsonPath('data.1.slug', 'veggies');

    // ── 2. STAFF credits the first mixed basket (no promotion live) ──────
    // HAND DERIVATION (standing 500bp→fee 100bp, veggies 200bp→fee 75bp,
    // fruits excluded; eligible 100,000 = 30,000 + 25,000 + 45,000):
    //   fruits   30,000 excluded                              → 0 / 0
    //   veggies  25,000 @200bp intdiv(25,000·200+9,999,10⁴)   → 500
    //            fee @75bp     intdiv(25,000·75 +9,999,10⁴)   → 188
    //   default  45,000 @500bp intdiv(45,000·500+9,999,10⁴)   → 2,250
    //            fee @100bp    intdiv(45,000·100+9,999,10⁴)   → 450
    //   TOTALS: cashback 2,750; fee 638  (NOT the aggregate 5,000 a
    //   whole-amount 500bp computation would give).
    expect(intdiv(25000 * 200 + 9999, 10000))->toBe(500)
        ->and(intdiv(25000 * 75 + 9999, 10000))->toBe(188)
        ->and(intdiv(45000 * 500 + 9999, 10000))->toBe(2250)
        ->and(intdiv(45000 * 100 + 9999, 10000))->toBe(450);

    $first = $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'LC-1',
        'eligible_amount' => 100000,
        'occurred_at' => $this->base->subHour()->toIso8601String(),
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => 'veggies', 'amount_laari' => 25000],
            ['category' => null, 'amount_laari' => 45000],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.rate_bp', 500)
        ->assertJsonPath('data.fee_bp', 100)
        ->assertJsonPath('data.cashback_laari', 2750)
        ->assertJsonPath('data.fee_laari', 638)
        ->assertJsonPath('data.lines.0.priced_by', 'excluded')
        ->assertJsonPath('data.lines.0.cashback_laari', 0)
        ->assertJsonPath('data.lines.0.fee_laari', 0)
        ->assertJsonPath('data.lines.1.priced_by', 'category')
        ->assertJsonPath('data.lines.1.effective_rate_bp', 200)
        ->assertJsonPath('data.lines.1.cashback_laari', 500)
        ->assertJsonPath('data.lines.1.fee_laari', 188)
        ->assertJsonPath('data.lines.2.priced_by', 'standing')
        ->assertJsonPath('data.lines.2.effective_rate_bp', 500)
        ->assertJsonPath('data.lines.2.cashback_laari', 2250)
        ->assertJsonPath('data.lines.2.fee_laari', 450);

    $firstId = (int) $first->json('data.id');

    expect(Transaction::query()->findOrFail($firstId)->promotion_id)->toBeNull();

    // ── 3. OWNER drafts and publishes the 6% promotion over HTTP ────────
    // 600bp (fee tier 100bp), min purchase 60,000 evaluated against the
    // WHOLE eligible amount, per-customer cap 4,500 laari.
    $this->actingAs($this->owner, 'merchant');

    $promoId = $this->postJson('/api/merchant/promotions', [
        'rate_bp' => 600,
        'starts_at' => $this->base->addHour()->toIso8601String(),
        'ends_at' => $this->base->addDays(3)->toIso8601String(),
        'min_purchase_laari' => 60000,
        'max_cashback_per_customer_laari' => 4500,
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->json('data.id');

    $this->postJson("/api/merchant/promotions/{$promoId}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // ── 4. STAFF credits the second mixed basket inside the promo window ─
    Carbon::setTestNow($this->base->addHours(2));
    $this->actingAs($this->staff, 'merchant');

    // HAND DERIVATION (promo 600bp→fee 100bp; whole eligible 100,000 ≥
    // min purchase 60,000 even though every single line is below it;
    // cap headroom starts at 4,500 — credit 1 predates the promo and is
    // unstamped, so it consumed nothing):
    //   fruits   20,000 excluded — the promo NEVER lifts an exclusion → 0/0
    //   veggies  30,000: max(600, own 200) = promo 600
    //            normal intdiv(30,000·600+9,999,10⁴) = 1,800 ≤ 4,500
    //            → granted 1,800; fee intdiv(30,000·100+9,999,10⁴) = 300
    //            headroom 4,500 − 1,800 = 2,700
    //   default  50,000: max(600, standing 500) = promo 600
    //            normal intdiv(50,000·600+9,999,10⁴) = 3,000 > 2,700
    //            → CLIPPED to 2,700 (≥ own-standing 2,500, so no floor);
    //            fee follows the reward GRANTED:
    //            intdiv(2,700·100 + 600−1, 600) = intdiv(270,599, 600) = 450
    //            headroom → 0 (cap consumed exactly)
    //   TOTALS: cashback 0+1,800+2,700 = 4,500; fee 0+300+450 = 750.
    expect(intdiv(30000 * 600 + 9999, 10000))->toBe(1800)
        ->and(intdiv(50000 * 600 + 9999, 10000))->toBe(3000)
        ->and(intdiv(50000 * 500 + 9999, 10000))->toBe(2500)
        ->and(intdiv(2700 * 100 + 599, 600))->toBe(450);

    $second = $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'LC-2',
        'eligible_amount' => 100000,
        'occurred_at' => $this->base->addMinutes(90)->toIso8601String(),
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 20000],
            ['category' => 'veggies', 'amount_laari' => 30000],
            ['category' => null, 'amount_laari' => 50000],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.rate_bp', 500) // row snapshot stays the STANDING base rate
        ->assertJsonPath('data.cashback_laari', 4500)
        ->assertJsonPath('data.fee_laari', 750)
        ->assertJsonPath('data.lines.0.priced_by', 'excluded')
        ->assertJsonPath('data.lines.0.effective_rate_bp', 0)
        ->assertJsonPath('data.lines.0.cashback_laari', 0)
        ->assertJsonPath('data.lines.1.priced_by', 'promotion')
        ->assertJsonPath('data.lines.1.effective_rate_bp', 600)
        ->assertJsonPath('data.lines.1.cashback_laari', 1800)
        ->assertJsonPath('data.lines.1.fee_laari', 300)
        ->assertJsonPath('data.lines.2.priced_by', 'promotion')
        ->assertJsonPath('data.lines.2.effective_rate_bp', 600)
        ->assertJsonPath('data.lines.2.cashback_laari', 2700)
        ->assertJsonPath('data.lines.2.fee_laari', 450);

    $secondId = (int) $second->json('data.id');

    expect(Transaction::query()->findOrFail($secondId)->promotion_id)->toBe($promoId);

    // Promo-priced line cashback for this customer = the cap, exactly.
    $promoGranted = (int) TransactionLine::query()
        ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
        ->where('transactions.promotion_id', $promoId)
        ->where('transactions.state', '!=', 'reversed')
        ->where('transaction_lines.priced_by', 'promotion')
        ->sum('transaction_lines.cashback_laari');

    expect($promoGranted)->toBe(4500);

    // Both accruals hit the ledger as the SUM of stored line integers:
    // receivable 3,388 + 5,250 = 8,638.
    $balances = new Balances;

    expect(DB::table('ledger_journals')->count())->toBe(2)
        ->and($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(8638)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(7250)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(1388);

    // ── 5. Validation windows close; both land on the settlement clock ───
    Carbon::setTestNow($this->base->addDays(4));

    $this->artisan('manfaa:sweep-validation')->assertExitCode(0);

    expect(Transaction::query()->findOrFail($firstId)->state->value)->toBe('payable_unfunded')
        ->and(Transaction::query()->findOrFail($secondId)->state->value)->toBe('payable_unfunded');

    // ── 6. OWNER settles both; ADMIN records and matches the payment ─────
    // Dues from the STORED integers: (2,750+638) + (4,500+750) = 8,638.
    $this->actingAs($this->owner, 'merchant');

    $settlementId = $this->postJson('/api/merchant/settlements', ['settle_all' => true])
        ->assertCreated()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.amount_due_laari', 8638)
        ->assertJsonCount(2, 'data.lines')
        ->json('data.id');

    $this->postJson("/api/merchant/settlements/{$settlementId}/submit")
        ->assertOk()
        ->assertJsonPath('data.state', 'awaiting_payment');

    $this->actingAs($this->admin, 'admin');

    $paymentId = $this->postJson("/api/admin/settlements/{$settlementId}/payments", [
        'amount' => 8638,
        'bank_ref' => 'BML-LC-88421',
    ])
        ->assertCreated()
        ->json('data.id');

    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_received_laari', 8638)
        ->assertJsonPath('data.lines.0.transaction.state', 'confirmed')
        ->assertJsonPath('data.lines.1.transaction.state', 'confirmed');

    // ── 7. The Reconciler runs clean; lines == rows == ledger ────────────
    $this->artisan('manfaa:reconcile')->assertExitCode(0);

    $run = ReconciliationRun::query()->sole();

    expect($run->status)->toBe('ok')
        ->and($run->issues)->toBeNull()
        // Receivable fully extinguished by the settlement; the liability
        // (7,250) survives until payout; revenue (1,388) is accrued.
        ->and($run->totals['receivable']['derived_laari'])->toBe(0)
        ->and($run->totals['receivable']['ledger_laari'])->toBe(0)
        ->and($run->totals['liability']['derived_laari'])->toBe(7250)
        ->and($run->totals['liability']['ledger_laari'])->toBe(7250)
        ->and($run->totals['revenue']['derived_laari'])->toBe(1388)
        ->and($run->totals['revenue']['ledger_laari'])->toBe(1388);

    // Derived-from-LINES equals the ledger exactly: the immutable per-line
    // snapshots are the source of truth the row totals and the ledger both
    // agree with, to the laari.
    $lineCashback = (int) TransactionLine::query()->sum('cashback_laari');
    $lineFees = (int) TransactionLine::query()->sum('fee_laari');
    $balances = new Balances;

    expect($lineCashback)->toBe(7250)
        ->and($lineFees)->toBe(1388)
        ->and($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe($lineCashback)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe($lineFees)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0);

    // Per transaction, the stored totals ARE the sums of the stored lines.
    foreach ([$firstId, $secondId] as $id) {
        $transaction = Transaction::query()->findOrFail($id);
        $lines = TransactionLine::query()->where('transaction_id', $id)->get();

        expect($lines)->toHaveCount(3)
            ->and($transaction->state->value)->toBe('confirmed')
            ->and($transaction->cashback_laari)->toBe((int) $lines->sum('cashback_laari'))
            ->and($transaction->fee_laari)->toBe((int) $lines->sum('fee_laari'));
    }
});
