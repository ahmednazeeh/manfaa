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
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Standing\Reconciler;
use App\Models\Adjustment;
use App\Models\AdminUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->fixture = SettlementFixture::payableBatch();
    $this->builder = app(SettlementBuilder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
 * §7 regression: a draft whose amount_due is fully netted to ZERO by
 * adjustment credits used to be submittable but unsettleable — no payment
 * path accepts zero laari — so its transactions sat payable_unfunded past
 * due_at until day 16 auto-suspended a merchant owing nothing, and the
 * customers' cashback never confirmed. A zero-due batch now settles at
 * submit: the applied credit IS the funding, every line allocates, the
 * transactions confirm.
 */
it('settles a fully credit-netted batch at submit instead of stranding it', function () {
    $transitions = app(TransitionService::class);
    $reversals = app(ReversalService::class);
    $allocator = app(SettlementAllocator::class);

    // T0 (due 2750) settles for real — its own batch, paid and matched, so
    // it is confirmed the only way production confirms anything — and is
    // THEN refunded: §6 routes the correction to a credit adjustment
    // (already_confirmed); the customer's reward survives.
    $t0 = $this->fixture->transactions[0];
    $own = $this->builder->createDraft($this->fixture->merchant, [$t0->id]);
    $this->builder->submit($own);
    $payment = $allocator->recordBankPayment($own, Laari::of(2750), 'BML-NET-2750');
    $allocator->matchPayment($payment, AdminUser::factory()->create());

    expect($t0->refresh()->state)->toBe(TransactionState::Confirmed);

    $outcome = $reversals->reverse($t0, Actor::system(), 'customer_refund', now()->toImmutable());

    expect($outcome->outcome)->toBe('adjustment_created')
        ->and($outcome->cause)->toBe('already_confirmed');

    // A fresh sale with the SAME due (100000 @ 200bp/75bp → 2750).
    $fresh = app(ManualCreditService::class)->credit(
        $this->fixture->merchant,
        $this->fixture->user,
        $this->fixture->customer->customer_code,
        'INV-NET-0',
        Laari::of(100000),
        null,
        now()->subHour()->toImmutable(),
    );
    $transitions->makePayable($fresh, Actor::system());

    $settlement = $this->builder->createDraft($this->fixture->merchant, [$fresh->id]);
    $adjustment = Adjustment::query()->sole();

    // 2750 − 2750: netted to exactly zero, and submittable.
    expect($settlement->amount_due_laari)->toBe(0)
        ->and($adjustment->refresh()->state)->toBe('applied');

    $settlement = $this->builder->submit($settlement);

    // Settled on the spot: no payment exists that could carry zero laari,
    // and nothing may sit on the clock for a batch that owes nothing.
    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->amount_received_laari)->toBe(0)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0)
        ->and($fresh->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($fresh->confirmed_at)->not->toBeNull();

    // Books: the fresh accrual's receivable was consumed by the applied
    // credit; the three remaining §4 lines are all that remain owed.
    $balances = new Balances;

    expect($balances->journalsAllBalance())->toBeTrue()
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(1375 + 5500 + 2200)
        ->and($balances->naturalBalance(AccountCode::PlatformFundedRewards))->toBe(2000);

    // The daily invariant agrees.
    $run = app(Reconciler::class)->run();

    expect($run->status)->toBe('ok')->and($run->issues)->toBeNull();
});
