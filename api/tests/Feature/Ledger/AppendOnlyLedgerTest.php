<?php

declare(strict_types=1);

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $account = LedgerAccount::factory()->create();

    $this->journal = LedgerJournal::query()->create([
        'reference_type' => 'test',
        'reference_id' => 1,
        'description' => 'Append-only check',
        'posted_at' => now(),
    ]);

    $this->entry = LedgerEntry::query()->create([
        'journal_id' => $this->journal->id,
        'account_id' => $account->id,
        'debit_laari' => 100,
        'credit_laari' => 0,
        'currency' => 'MVR',
    ]);
});

it('refuses to update a ledger journal', function () {
    expect(fn () => $this->journal->update(['description' => 'rewritten history']))
        ->toThrow(LogicException::class);

    expect($this->journal->fresh()->description)->toBe('Append-only check');
});

it('refuses to delete a ledger journal', function () {
    expect(fn () => $this->journal->delete())->toThrow(LogicException::class);

    expect(LedgerJournal::query()->whereKey($this->journal->id)->exists())->toBeTrue();
});

it('refuses to update a ledger entry', function () {
    expect(fn () => $this->entry->update(['debit_laari' => 999]))
        ->toThrow(LogicException::class);

    expect($this->entry->fresh()->debit_laari)->toBe(100);
});

it('refuses to delete a ledger entry', function () {
    expect(fn () => $this->entry->delete())->toThrow(LogicException::class);

    expect(LedgerEntry::query()->whereKey($this->entry->id)->exists())->toBeTrue();
});

it('carries no updated_at column on either append-only table', function () {
    expect(Schema::hasColumn('ledger_journals', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('ledger_entries', 'updated_at'))->toBeFalse()
        ->and(LedgerJournal::UPDATED_AT)->toBeNull()
        ->and(LedgerEntry::UPDATED_AT)->toBeNull();
});
