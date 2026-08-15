<?php

declare(strict_types=1);

use App\Domain\Adjustment\BackdatedIrreversibleException;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\CreditRecorder;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementBuilder;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYears(2),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $this->admin = AdminUser::factory()->create();
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A PLAN §1 backdated credit — a sale older than the merchant's validation
 * window plus the grace days — then held for fraud review. Backdated rows
 * skip on_hold at creation (task #23), so the only way one reaches this queue
 * is a human holding it afterwards, which is exactly what this does.
 */
function backdatedHold(Merchant $merchant, MerchantUser $user, string $invoiceNo, CarbonImmutable $now): Transaction
{
    $transaction = app(ManualCreditService::class)
        ->credit($merchant, $user, '482917', $invoiceNo, Laari::of(125_000), null, $now->subDays(30));

    expect($transaction->refresh()->backdated)->toBeTrue()
        ->and($transaction->state)->toBe(TransactionState::PayableUnfunded)
        ->and($transaction->reason_code)->toBe(CreditRecorder::BACKDATED_REASON);

    app(TransitionService::class)->hold($transaction, Actor::admin(9_999), 'fraud_review');

    return $transaction->refresh();
}

it('releases a held backdated credit back onto a freshly stamped clock, keeping the permanent flag', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = backdatedHold($this->merchant, $this->user, 'INV-BD-1', $now);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release", ['note' => 'Store produced the original receipt.'])
        ->assertOk()
        ->assertJsonPath('data.state', 'payable_unfunded')
        ->assertJsonPath('data.backdated', true)
        ->assertJsonPath('data.due_at', $now->addDays(15)->toIso8601String());

    $transaction->refresh();

    // reason_code moves on (backdated_final → admin_release); the permanent
    // `backdated` column is what irreversibility is decided from, which is
    // exactly why task #23 added it.
    expect($transaction->backdated)->toBeTrue()
        ->and($transaction->reason_code)->toBe('admin_release')
        ->and($transaction->clock_start_at?->getTimestamp())->toBe($now->getTimestamp());
});

it('keeps a backdated credit merchant-irreversible while the admin queue can still reject it', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = backdatedHold($this->merchant, $this->user, 'INV-BD-2', $now);

    // PLAN §1, unchanged by this queue: the party who chose to backdate the
    // credit cannot take it back — not in place, not as a credit memo.
    expect(fn () => app(ReversalService::class)->reverse(
        $transaction,
        Actor::merchantUser($this->user->id),
        'customer_refund',
        $now,
    ))->toThrow(BackdatedIrreversibleException::class);

    expect($transaction->refresh()->state)->toBe(TransactionState::OnHold);

    // The other half of the same decision — "admin adjustment only". An admin
    // acting through the admin guard IS that adjustment, so the queue's Reject
    // runs the ordinary §9.2 tree and mirrors the accrual.
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", [
            'reason' => 'Backfilled sale the store cannot evidence; reversed as an admin correction.',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'reversed')
        ->assertJsonPath('data.reason_code', 'admin_reject');

    expect($transaction->refresh()->state)->toBe(TransactionState::Reversed)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('surfaces the backdated flag on the queue row so the panel can warn before either action', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $backdated = backdatedHold($this->merchant, $this->user, 'INV-BD-3', $now);

    $ordinary = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-BD-4', Laari::of(125_000), null, $now->subHour());
    app(TransitionService::class)->hold($ordinary->refresh(), Actor::admin(9_999), 'fraud_review');

    $response = $this->actingAs($this->admin, 'admin')->getJson('/api/admin/holds')->assertOk();

    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows[$backdated->id]['backdated'])->toBeTrue()
        ->and($rows[$ordinary->id]['backdated'])->toBeFalse()
        // The backdated row was held out of payable, so releasing it returns
        // it there — with the clock restarted, not resumed.
        ->and($rows[$backdated->id]['pre_hold_state'])->toBe('payable_unfunded')
        ->and($rows[$backdated->id]['release_target']['starts_clock'])->toBeTrue();
});

it('refuses to reject a hold whose line is frozen in a live settlement, leaving no memo behind', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-BD-5', Laari::of(125_000), null, $now->subDays(5));

    app(TransitionService::class)->makePayable($transaction->refresh(), Actor::system());

    $settlement = app(SettlementBuilder::class)
        ->createDraft($this->merchant, [$transaction->id]);
    app(SettlementBuilder::class)->submit($settlement);

    app(TransitionService::class)->hold($transaction->refresh(), Actor::admin(9_999), 'fraud_review');

    // The §9.2 tree would answer this with a credit memo, which is not what
    // the queue's Reject promises — so it refuses, and the rollback leaves
    // neither an adjustment nor a state change behind.
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", ['reason' => 'Fraudulent sale inside a submitted batch.'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'locked_in_settlement');

    expect($transaction->refresh()->state)->toBe(TransactionState::OnHold)
        ->and($transaction->adjustments()->count())->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});
