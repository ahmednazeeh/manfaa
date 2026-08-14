<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Settlement\InsufficientWalletBalanceException;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\WalletFunding;
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
    $this->wallet = app(WalletFunding::class);
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

it('settles the whole batch from the wallet: top up 20000, settle 11825, 8175 remains', function () {
    $this->wallet->recordTopUp($this->fixture->merchant, Laari::of(20000), 'BML-TOPUP-1');

    expect($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(20000)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(20000);

    $settlement = $this->builder->createDraft($this->fixture->merchant);
    $this->builder->submit($settlement);

    $this->wallet->settleFromWallet($settlement->refresh(), $this->fixture->user);
    $settlement->refresh();

    // Same path as bank settlement: same states, same confirm flow — only
    // the funding leg differs (walletSettle instead of bankSettlementReceived).
    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->funding_method)->toBe('wallet')
        ->and($settlement->amount_received_laari)->toBe(11825)
        ->and($settlement->lines()->whereNull('allocated_at')->count())->toBe(0);

    foreach ($this->fixture->transactions as $transaction) {
        expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
            ->and($transaction->confirmed_at)->not->toBeNull();

        $event = $transaction->events()->where('to_state', 'confirmed')->sole();
        expect($event->reason_code)->toBe('settlement_allocated')
            ->and($event->actor_type)->toBe('merchant_user')
            ->and($event->actor_id)->toBe($this->fixture->user->id);
    }

    expect($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(20000 - 11825)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(8175)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('never funds a batch partially from the wallet', function () {
    $this->wallet->recordTopUp($this->fixture->merchant, Laari::of(5000), 'BML-TOPUP-2');

    $settlement = $this->builder->createDraft($this->fixture->merchant);
    $this->builder->submit($settlement);

    expect(fn () => $this->wallet->settleFromWallet($settlement->refresh(), $this->fixture->user))
        ->toThrow(InsufficientWalletBalanceException::class);

    // Nothing moved: no allocation, no confirmation, balance intact.
    expect($settlement->refresh()->state)->toBe(SettlementState::AwaitingPayment)
        ->and($settlement->lines()->whereNotNull('allocated_at')->count())->toBe(0)
        ->and($this->fixture->transactions[0]->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($this->fixture->merchant->wallet()->sole()->balance_laari)->toBe(5000)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(11825);
});

it('refuses wallet settlement on a draft', function () {
    $this->wallet->recordTopUp($this->fixture->merchant, Laari::of(20000), 'BML-TOPUP-3');

    $settlement = $this->builder->createDraft($this->fixture->merchant);

    expect(fn () => $this->wallet->settleFromWallet($settlement, $this->fixture->user))
        ->toThrow(InvalidSettlementStateException::class);
});
