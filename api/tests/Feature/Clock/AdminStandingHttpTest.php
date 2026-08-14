<?php

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\ReconciliationRun;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('rejects unauthenticated access to the standing endpoints', function () {
    $this->getJson('/api/admin/merchants')->assertUnauthorized();
    $this->getJson('/api/admin/merchants/1/notices')->assertUnauthorized();
    $this->getJson('/api/admin/reconciliation')->assertUnauthorized();
});

it('lists merchant standing with outstanding and overdue totals summed from stored integers', function () {
    $now = CarbonImmutable::parse('2026-08-20T12:00:00+00:00');
    Carbon::setTestNow($now);

    $merchant = Merchant::factory()->suspended()->create(['name' => 'Alpha Mart']);

    // One overdue line and one still inside its 15 days.
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $now->subDays(20),
        'due_at' => $now->subDays(5),
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $now->subDays(2),
        'due_at' => $now->addDays(13),
        'cashback_laari' => 1_000,
        'fee_laari' => 375,
        'fee_gst_laari' => 0,
    ]);
    // Confirmed lines are no longer outstanding.
    Transaction::factory()->for($merchant)->create([
        'state' => 'confirmed',
        'cashback_laari' => 9_999,
        'fee_laari' => 9_999,
        'fee_gst_laari' => 0,
    ]);

    $clean = Merchant::factory()->create(['name' => 'Beta Store']);

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson('/api/admin/merchants')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Mart')
        ->assertJsonPath('data.0.status', 'suspended')
        ->assertJsonPath('data.0.open_payable_count', 2)
        ->assertJsonPath('data.0.outstanding_laari', 2_750 + 1_375)
        ->assertJsonPath('data.0.overdue_laari', 2_750)
        ->assertJsonPath('data.1.id', $clean->id)
        ->assertJsonPath('data.1.outstanding_laari', 0)
        ->assertJsonPath('data.1.overdue_laari', 0)
        ->assertJsonPath('data.1.oldest_due_at', null);
});

it('returns a merchant notice trail newest first', function () {
    $now = CarbonImmutable::parse('2026-08-20T12:00:00+00:00');
    $merchant = Merchant::factory()->create();

    foreach ([['reminder_day10', $now->subDays(6)], ['urgent_day13', $now->subDays(3)], ['suspended', $now->subDay()]] as [$type, $sentAt]) {
        MerchantNotice::query()->create([
            'merchant_id' => $merchant->id,
            'type' => $type,
            'channel' => 'log',
            'payload' => ['days_since_clock_start' => 10],
            'sent_at' => $sentAt,
        ]);
    }

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson("/api/admin/merchants/{$merchant->id}/notices")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.type', 'suspended')
        ->assertJsonPath('data.1.type', 'urgent_day13')
        ->assertJsonPath('data.2.type', 'reminder_day10')
        ->assertJsonPath('data.2.payload.days_since_clock_start', 10);
});

it('returns the latest reconciliation runs, honouring the limit', function () {
    $now = CarbonImmutable::parse('2026-08-20T02:00:00+00:00');

    foreach (range(1, 3) as $day) {
        ReconciliationRun::query()->create([
            'ran_at' => $now->subDays(3 - $day),
            'status' => $day === 2 ? 'divergent' : 'ok',
            'journals_checked' => $day,
            'issues' => $day === 2 ? [['kind' => 'unbalanced_journal', 'journal_id' => 7]] : null,
            'totals' => ['receivable' => ['derived_laari' => 0, 'ledger_laari' => 0]],
        ]);
    }

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson('/api/admin/reconciliation?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'ok')
        ->assertJsonPath('data.0.journals_checked', 3)
        ->assertJsonPath('data.1.status', 'divergent')
        ->assertJsonPath('data.1.issues.0.journal_id', 7);
});
