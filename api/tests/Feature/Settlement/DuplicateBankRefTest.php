<?php

declare(strict_types=1);

use App\Domain\Settlement\SettlementBuilder;
use App\Models\AdminUser;
use App\Models\SettlementPayment;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->fixture = SettlementFixture::payableBatch();
    $this->admin = AdminUser::factory()->create();

    $builder = app(SettlementBuilder::class);
    $this->settlement = $builder->createDraft($this->fixture->merchant);
    $builder->submit($this->settlement);
    $this->settlement->refresh();

    $this->actingAs($this->admin, 'admin');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The bank-settlement cash journals referencing the settlement: [count, total
 * debit laari] — how much cash was actually booked, and in how many postings.
 */
function dupRefCashBooked(int $settlementId): array
{
    $rows = DB::table('ledger_entries')
        ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
        ->where('ledger_journals.reference_type', 'settlement')
        ->where('ledger_journals.reference_id', $settlementId)
        ->where('ledger_journals.description', 'Bank settlement received')
        ->get();

    return [
        $rows->pluck('journal_id')->unique()->count(),
        (int) $rows->sum('debit_laari'),
    ];
}

it('records the same transfer only once and books its cash exactly once', function () {
    $paymentId = $this->postJson("/api/admin/settlements/{$this->settlement->id}/payments", [
        'amount' => SettlementFixture::BATCH_DUE_LAARI,
        'bank_ref' => 'BML-20260805-77',
    ])
        ->assertCreated()
        ->json('data.id');

    // The same transfer submitted again — double click, retried request,
    // replayed statement row — is a conflict and creates no second payment.
    $this->postJson("/api/admin/settlements/{$this->settlement->id}/payments", [
        'amount' => SettlementFixture::BATCH_DUE_LAARI,
        'bank_ref' => 'BML-20260805-77',
    ])->assertConflict();

    expect(SettlementPayment::query()->count())->toBe(1);

    // A genuinely different transfer on the same batch still records fine.
    $this->postJson("/api/admin/settlements/{$this->settlement->id}/payments", [
        'amount' => 500,
        'bank_ref' => 'BML-20260805-78',
    ])->assertCreated();

    expect(SettlementPayment::query()->count())->toBe(2);

    // Matching the single surviving copy of the transfer settles the batch
    // and books the cash once — one journal, for the amount, no more.
    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_received_laari', SettlementFixture::BATCH_DUE_LAARI);

    [$journals, $debits] = dupRefCashBooked($this->settlement->id);

    expect($journals)->toBe(1)
        ->and($debits)->toBe(SettlementFixture::BATCH_DUE_LAARI);
});
