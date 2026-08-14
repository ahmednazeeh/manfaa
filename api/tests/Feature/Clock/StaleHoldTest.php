<?php

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\ReconciliationRun;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * One credit through the real accrual path, put on_hold at $heldAt — so the
 * ledger matches the transactions table and the hold's last state change is
 * the transaction_events row written at $heldAt.
 */
function creditHeldAt(
    CarbonImmutable $heldAt,
    Merchant $merchant,
    MerchantUser $user,
    string $customerCode,
    string $invoiceNo,
    int $eligibleLaari,
): Transaction {
    Carbon::setTestNow($heldAt);

    $transaction = app(ManualCreditService::class)
        ->credit($merchant, $user, $customerCode, $invoiceNo, Laari::of($eligibleLaari), null, $heldAt->subHour());

    app(TransitionService::class)->hold($transaction, Actor::system(), 'dispute_review');

    return $transaction;
}

it('surfaces a 31-day-old hold in the reconciliation issues while the run stays ok', function () {
    $now = CarbonImmutable::parse('2026-08-20T02:00:00+00:00');

    $merchant = Merchant::factory()->create();
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => $now->subYears(2),
        'effective_to' => null,
    ]);
    $user = MerchantUser::factory()->for($merchant)->create();
    $customer = Customer::factory()->create();

    // 100000 eligible @200bp/75bp, held 31 days ago — stale. The 50000 one
    // held 5 days ago is a live review, not backlog.
    $stale = creditHeldAt($now->subDays(31), $merchant, $user, $customer->customer_code, 'INV-SH-1', 100_000);
    creditHeldAt($now->subDays(5), $merchant, $user, $customer->customer_code, 'INV-SH-2', 50_000);

    Carbon::setTestNow($now);
    $this->artisan('manfaa:reconcile')->assertExitCode(0);

    $run = ReconciliationRun::query()->sole();

    // Held rows are review backlog, not corruption: surfaced in issues, but
    // never written off automatically and never a divergence.
    expect($run->status)->toBe('ok')
        ->and($run->issues)->toHaveCount(1)
        ->and($run->issues[0]['kind'])->toBe('stale_holds')
        ->and($run->issues[0]['count'])->toBe(1)
        ->and($run->issues[0]['total_laari'])->toBe(2_750)
        ->and($run->issues[0]['transactions'][0]['id'])->toBe($stale->id)
        ->and($run->issues[0]['transactions'][0]['laari'])->toBe(2_750)
        ->and($stale->refresh()->state)->toBe(TransactionState::OnHold);
});

it('shows a stale-hold count and total on the admin merchant standing rows', function () {
    $now = CarbonImmutable::parse('2026-08-20T12:00:00+00:00');

    $merchant = Merchant::factory()->create(['name' => 'Alpha Mart']);
    $clean = Merchant::factory()->create(['name' => 'Beta Store']);

    // A hold unchanged for 31 days counts; a 5-day-old hold does not.
    Carbon::setTestNow($now->subDays(31));
    Transaction::factory()->for($merchant)->create([
        'state' => 'on_hold',
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);
    Carbon::setTestNow($now->subDays(5));
    Transaction::factory()->for($merchant)->create([
        'state' => 'on_hold',
        'cashback_laari' => 1_000,
        'fee_laari' => 375,
        'fee_gst_laari' => 0,
    ]);

    Carbon::setTestNow($now);
    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson('/api/admin/merchants')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Mart')
        ->assertJsonPath('data.0.stale_hold_count', 1)
        ->assertJsonPath('data.0.stale_hold_laari', 2_750)
        ->assertJsonPath('data.1.id', $clean->id)
        ->assertJsonPath('data.1.stale_hold_count', 0)
        ->assertJsonPath('data.1.stale_hold_laari', 0);
});
