<?php

use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects a duplicate invoice_no for the same merchant', function () {
    $merchant = Merchant::factory()->create();

    Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'invoice_no' => 'INV-1001',
    ]);

    expect(fn () => Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'invoice_no' => 'INV-1001',
    ]))->toThrow(QueryException::class);
});

it('scopes idempotency_key uniqueness to the merchant', function () {
    $merchantA = Merchant::factory()->create();
    $merchantB = Merchant::factory()->create();
    $key = '3d1f9c4e-5b3a-4a2e-9c8d-1f2e3d4c5b6a';

    Transaction::factory()->create(['merchant_id' => $merchantA->id, 'idempotency_key' => $key]);

    // Another merchant may derive the identical key (§9.2 scopes idempotency
    // to the merchant's own writes)…
    Transaction::factory()->create(['merchant_id' => $merchantB->id, 'idempotency_key' => $key]);

    // …but a replay by the same merchant collides.
    expect(fn () => Transaction::factory()->create([
        'merchant_id' => $merchantA->id,
        'idempotency_key' => $key,
    ]))->toThrow(QueryException::class);
});

it('rejects an invalid transaction state through the check constraint', function () {
    $merchant = Merchant::factory()->create();

    expect(fn () => DB::table('transactions')->insert([
        'merchant_id' => $merchant->id,
        'origin' => 'pos',
        'invoice_no' => 'INV-2001',
        'eligible_laari' => 100000,
        'rate_bp' => 200,
        'fee_bp' => 75,
        'state' => 'not_a_state',
        'occurred_at' => now(),
        'received_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a negative debit on ledger entries', function () {
    $account = LedgerAccount::factory()->create();
    $journal = LedgerJournal::create([
        'reference_type' => 'test',
        'reference_id' => 1,
        'description' => 'Schema check',
        'posted_at' => now(),
    ]);

    expect(fn () => DB::table('ledger_entries')->insert([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit_laari' => -100,
        'credit_laari' => 0,
        'currency' => 'MVR',
    ]))->toThrow(QueryException::class);
});
