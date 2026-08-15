<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\MerchantNotice;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    $this->fixture = SettlementFixture::payableBatch();
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Receipt-first submission of the whole batch; returns the settlement id. */
function submitOverdueReceipt(int $amountLaari, string $bankRef): int
{
    return test()->actingAs(test()->fixture->user, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => $amountLaari,
            'bank_ref' => $bankRef,
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->json('data.id');
}

/** Past every line's due_at — day 16+ of the §7 clock. */
function pastDue(): void
{
    Carbon::setTestNow(CarbonImmutable::parse(SettlementFixture::BASE)->addDays(20));
}

/*
 * §7's day-16 suspension is the platform's only credit control, and it is
 * aimed at a merchant who has NOT paid. A merchant who transferred and
 * uploaded the slip on time has paid; their lines only sit payable_unfunded
 * because nothing leaves that state until an admin matches the receipt
 * (LineAllocator runs at match time). Suspending on that makes the
 * platform's own review latency the credit control.
 */

it('does not suspend a merchant whose overdue lines sit on a batch under receipt review', function () {
    submitOverdueReceipt(SettlementFixture::BATCH_DUE_LAARI, 'BML-DUE-1');

    pastDue();

    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($this->fixture->merchant->refresh()->status)->toBe('active')
        ->and(MerchantNotice::query()->where('type', 'suspended')->count())->toBe(0);
});

it('suspends the moment the receipt is rejected — the credit control still bites', function () {
    $settlementId = submitOverdueReceipt(SettlementFixture::BATCH_DUE_LAARI, 'BML-DUE-2');

    pastDue();

    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect($this->fixture->merchant->refresh()->status)->toBe('active');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/settlements/{$settlementId}/reject", ['reason' => 'No transfer with this reference arrived.'])
        ->assertOk();

    // The lines are released back to payable_unfunded and nothing shields
    // them any more: the merchant is overdue and unpaid.
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($this->fixture->merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'suspended')->sole()->payload['overdue_transactions'])->toBe(4);
});

it('suspends on the lines a matched partial receipt did not cover', function () {
    // 2,750 + 1,375 covers the two oldest lines only; the rest stay frozen
    // on a partially_settled batch with no pending receipt left.
    $settlementId = submitOverdueReceipt(4_125, 'BML-DUE-3');

    pastDue();

    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect($this->fixture->merchant->refresh()->status)->toBe('active');

    $paymentId = SettlementPayment::query()->sole()->id;

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'partially_settled');

    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($this->fixture->merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'suspended')->sole()->payload['overdue_transactions'])->toBe(2);
});

it('keeps an already-suspended merchant suspended until the transfer is actually verified', function () {
    pastDue();

    // Suspended first, on genuinely unpaid debt.
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect($this->fixture->merchant->refresh()->status)->toBe('suspended');

    // An unreviewed slip must not be able to unlock the door by itself:
    // reinstatement stays on the WIDER overdue definition.
    $settlementId = submitOverdueReceipt(SettlementFixture::BATCH_DUE_LAARI, 'BML-DUE-4');

    $this->artisan('manfaa:reinstate')->assertExitCode(0);
    expect($this->fixture->merchant->refresh()->status)->toBe('suspended');

    $paymentId = SettlementPayment::query()->sole()->id;

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk();

    expect(Settlement::query()->findOrFail($settlementId)->state->value)->toBe('settled');

    $this->artisan('manfaa:reinstate')->assertExitCode(0);
    expect($this->fixture->merchant->refresh()->status)->toBe('active');
});
