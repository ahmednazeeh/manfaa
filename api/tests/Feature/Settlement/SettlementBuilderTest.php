<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Money\Laari;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\NotEligibleForSettlementException;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementGuard;
use App\Domain\Settlement\SettlementLockedException;
use App\Domain\Settlement\SettlementState;
use App\Models\SettlementLine;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

it('drafts the §4 batch: totals are sums of line snapshots, due_at is the earliest line due', function () {
    $settlement = $this->builder->createDraft($this->fixture->merchant);

    expect($settlement->state)->toBe(SettlementState::Draft)
        ->and($settlement->reference)->toBe('ST-2026-00001')
        ->and($settlement->lines()->count())->toBe(4)
        ->and($settlement->cashback_total_laari)->toBe(8600)
        ->and($settlement->fee_total_laari)->toBe(3225)
        ->and($settlement->fee_gst_total_laari)->toBe(0)
        ->and($settlement->amount_due_laari)->toBe(SettlementFixture::BATCH_DUE_LAARI)
        ->and($settlement->sale_total_laari)->toBe(430000)
        ->and($settlement->amount_received_laari)->toBe(0);

    // Line snapshots are the transactions' stored integers, and the batch due
    // is exactly their sum — nothing recomputed from a rate.
    $lineDues = $settlement->lines->map(
        fn ($line) => $line->cashback_laari + $line->fee_laari + $line->fee_gst_laari,
    )->sort()->values()->all();

    expect($lineDues)->toBe([1375, 2200, 2750, 5500])
        ->and($settlement->amount_due_laari)->toBe(array_sum($lineDues));

    // §7: batch due date = earliest line's due date, not the creation date.
    expect($settlement->due_at->equalTo($this->fixture->transactions[0]->due_at))->toBeTrue();
});

it('numbers references sequentially within the year', function () {
    $ids = $this->fixture->transactionIds();

    $first = $this->builder->createDraft($this->fixture->merchant, [$ids[0]]);
    $second = $this->builder->createDraft($this->fixture->merchant, [$ids[1]]);

    expect($first->reference)->toBe('ST-2026-00001')
        ->and($second->reference)->toBe('ST-2026-00002');
});

it('excludes transactions already on an open settlement from settle-all', function () {
    $ids = $this->fixture->transactionIds();

    $partial = $this->builder->createDraft($this->fixture->merchant, [$ids[0], $ids[1]]);

    // Settle-all only picks up the two transactions the draft did not claim.
    $rest = $this->builder->createDraft($this->fixture->merchant);

    expect($rest->lines()->count())->toBe(2)
        ->and($rest->lines()->pluck('transaction_id')->sort()->values()->all())
        ->toBe([$ids[2], $ids[3]])
        ->and($rest->amount_due_laari)->toBe(5500 + 2200);

    // Naming a claimed transaction explicitly throws.
    $this->builder->cancel($rest);
    expect(fn () => $this->builder->createDraft($this->fixture->merchant, [$ids[0]]))
        ->toThrow(NotEligibleForSettlementException::class);

    // Cancelling releases the claim: the transactions are eligible again.
    $this->builder->cancel($partial);
    $again = $this->builder->createDraft($this->fixture->merchant, [$ids[0], $ids[1]]);

    expect($again->lines()->count())->toBe(2);
});

it('freezes lines once the settlement leaves draft', function () {
    $ids = $this->fixture->transactionIds();
    $settlement = $this->builder->createDraft($this->fixture->merchant, [$ids[0], $ids[1]]);

    $this->builder->submit($settlement);
    expect($settlement->refresh()->state)->toBe(SettlementState::AwaitingPayment);

    expect(fn () => $this->builder->addLines($settlement, [$ids[2]]))
        ->toThrow(SettlementLockedException::class);

    expect(fn () => $this->builder->removeLine($settlement, $settlement->lines()->first()))
        ->toThrow(SettlementLockedException::class);

    // §7 locked batches: a transaction on a non-draft settlement is
    // off-limits to reversal paths.
    expect(fn () => SettlementGuard::assertNotLockedInSettlement($this->fixture->transactions[0]))
        ->toThrow(SettlementLockedException::class);

    // A draft does not lock: the unclaimed transaction passes the guard.
    SettlementGuard::assertNotLockedInSettlement($this->fixture->transactions[2]);
    expect(true)->toBeTrue();
});

it('enforces one live settlement claim per transaction at the database level', function () {
    $ids = $this->fixture->transactionIds();

    $first = $this->builder->createDraft($this->fixture->merchant, [$ids[0]]);
    $second = $this->builder->createDraft($this->fixture->merchant, [$ids[1]]);

    // A racing draft build that slipped past the application-level
    // NOT EXISTS guard (the FOR UPDATE lock does not re-run the subquery for
    // an unmodified transactions row) is stopped by the unique index — the
    // merchant can never be invoiced twice for one transaction.
    expect(fn () => DB::transaction(fn () => SettlementLine::query()->create([
        'settlement_id' => $second->id,
        'transaction_id' => $ids[0],
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
        'currency' => 'MVR',
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect($first->lines()->count())->toBe(1)
        ->and($second->refresh()->lines()->count())->toBe(1);
});

it('releases a cancelled batch\'s claim by deleting its lines', function () {
    $settlement = $this->builder->createDraft($this->fixture->merchant);
    $this->builder->submit($settlement);
    $this->builder->cancel($settlement);

    // The claim is the line row: cancellation removes it, so the unique
    // index never blocks the transactions from joining a fresh batch.
    expect($settlement->refresh()->state)->toBe(SettlementState::Cancelled)
        ->and(SettlementLine::query()->where('settlement_id', $settlement->id)->count())->toBe(0);

    $again = $this->builder->createDraft($this->fixture->merchant);
    expect($again->lines()->count())->toBe(4);
});

it('refuses to cancel once a payment has been recorded', function () {
    $settlement = $this->builder->createDraft($this->fixture->merchant);
    $this->builder->submit($settlement);

    app(SettlementAllocator::class)->recordBankPayment($settlement, Laari::of(6000), 'BML-77001');

    expect(fn () => $this->builder->cancel($settlement->refresh()))
        ->toThrow(InvalidSettlementStateException::class);

    expect($settlement->refresh()->state)->toBe(SettlementState::PaymentReview);
});

it('cancels an unpaid awaiting_payment batch and releases its transactions', function () {
    $settlement = $this->builder->createDraft($this->fixture->merchant);
    $this->builder->submit($settlement);

    $this->builder->cancel($settlement);

    expect($settlement->refresh()->state)->toBe(SettlementState::Cancelled)
        ->and($this->fixture->transactions[0]->refresh()->state)->toBe(TransactionState::PayableUnfunded);

    // Everything is eligible again.
    $again = $this->builder->createDraft($this->fixture->merchant);
    expect($again->lines()->count())->toBe(4);
});

it('rejects an empty submit and an empty settle-all', function () {
    $ids = $this->fixture->transactionIds();
    $settlement = $this->builder->createDraft($this->fixture->merchant, $ids);

    expect(fn () => $this->builder->createDraft($this->fixture->merchant))
        ->toThrow(NotEligibleForSettlementException::class);

    $settlement->lines()->delete();

    expect(fn () => $this->builder->submit($settlement))
        ->toThrow(NotEligibleForSettlementException::class);
});
