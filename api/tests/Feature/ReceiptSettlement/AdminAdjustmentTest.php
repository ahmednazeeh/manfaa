<?php

declare(strict_types=1);

use App\Domain\Adjustment\BackdatedIrreversibleException;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Standing\Reconciler;
use App\Models\Adjustment;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T10:00:00+00:00'));

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
    $this->admin = AdminUser::factory()->create();
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A backdated credit — payable immediately, merchant-irreversible (PLAN §1). */
function backfilled(string $invoiceNo = 'INV-BACKFILL-1'): Transaction
{
    return app(ManualCreditService::class)->credit(
        test()->merchant,
        test()->user,
        '482917',
        $invoiceNo,
        Laari::of(100_000),
        null,
        now()->subDays(10)->toImmutable(),
    )->refresh();
}

/*
 * PLAN §1 makes a backdated credit merchant-irreversible "(admin adjustment
 * only)", and docs/openapi.yaml tells vendors the same in three places:
 * "a correction is an admin adjustment", "only an admin adjustment can
 * correct it", "Corrections are admin adjustments — contact the platform".
 * The escape hatch has to actually exist, or a POS that backfills a week of
 * already-refunded sales leaves the merchant paying cashback on returned
 * goods with no in-app way to put it right.
 */

it('lets an admin correct a backdated credit the merchant cannot touch', function () {
    $transaction = backfilled();

    expect($transaction->backdated)->toBeTrue()
        ->and($transaction->state)->toBe(TransactionState::PayableUnfunded);

    // The merchant is still refused, in the same state, on the same row.
    expect(fn () => app(ReversalService::class)->reverse(
        $transaction,
        Actor::merchantUser($this->user->id),
        'customer_refund',
        now()->toImmutable(),
    ))->toThrow(BackdatedIrreversibleException::class);

    $accruedBefore = $this->balances->accountBalance(AccountCode::MerchantReceivable);
    expect($accruedBefore)->toBeGreaterThan(0);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/transactions/{$transaction->id}/adjustments", [
            'reason' => 'customer_refund',
            'note' => 'Till outage backfill; this sale was refunded the same day.',
        ])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'reversed')
        ->assertJsonPath('data.transaction.state', 'reversed');

    // Pre-confirmation and on no batch: reversed in place, accrual mirrored
    // from the STORED integers — the receivable is whole again.
    expect($transaction->refresh()->state)->toBe(TransactionState::Reversed)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    expect(app(Reconciler::class)->run()->status)->toBe('ok');
});

it('records the admin actor and the required note on the transaction history', function () {
    $transaction = backfilled('INV-BACKFILL-NOTE');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/transactions/{$transaction->id}/adjustments", [
            'reason' => 'duplicate',
            'note' => 'Double-posted by the vendor after the outage.',
        ])
        ->assertOk();

    $event = $transaction->events()->where('to_state', 'reversed')->sole();

    expect($event->actor_type)->toBe('admin')
        ->and($event->actor_id)->toBe($this->admin->id)
        ->and($event->reason_code)->toBe('duplicate')
        ->and($event->meta['note'])->toBe('Double-posted by the vendor after the outage.');
});

it('creates a credit memo instead when the backdated reward is already confirmed', function () {
    $transaction = backfilled('INV-BACKFILL-CONFIRMED');

    app(TransitionService::class)->confirm($transaction, Actor::system());
    expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/transactions/{$transaction->id}/adjustments", [
            'reason' => 'customer_refund',
            'note' => 'Refunded after settlement; credit the next batch.',
        ])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'adjustment_created')
        ->assertJsonPath('data.cause', 'already_confirmed');

    $adjustment = Adjustment::query()->sole();

    // §13: confirmed is near-final — the correction is a memo, never an edit.
    expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and($adjustment->state)->toBe('pending')
        ->and($adjustment->amount_laari)->toBeLessThan(0)
        ->and($adjustment->note)->toContain('Admin adjustment')
        ->and($adjustment->note)->toContain('Refunded after settlement; credit the next batch.');

    // A memo is a memo: creation posts nothing. Its credit journal fires once,
    // at application to a batch (SettlementBuilder::createDraft). No
    // reconciler assertion here — this fixture confirms the reward through
    // the state machine WITHOUT a settlement paying for it, so the derived
    // receivable diverges from the ledger for that reason alone, memo or no
    // memo. The in-place case above proves the ledger side end to end.
    expect($this->balances->journalsAllBalance())->toBeTrue()
        ->and(DB::table('ledger_journals')->where('reference_type', 'adjustment')->where('reference_id', $adjustment->id)->count())->toBe(0);
});

it('refuses the correction without a note, and refuses it on a terminal row', function () {
    $transaction = backfilled('INV-BACKFILL-GUARD');

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/transactions/{$transaction->id}/adjustments", ['reason' => 'other'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['note']);

    $this->postJson("/api/admin/transactions/{$transaction->id}/adjustments", [
        'reason' => 'not_a_reason',
        'note' => 'nope',
    ])->assertUnprocessable();

    $this->postJson("/api/admin/transactions/{$transaction->id}/adjustments", [
        'reason' => 'other',
        'note' => 'Correcting the backfill.',
    ])->assertOk();

    // Reversed is terminal for everyone, admins included.
    $this->postJson("/api/admin/transactions/{$transaction->id}/adjustments", [
        'reason' => 'other',
        'note' => 'Again, for good measure.',
    ])->assertConflict();
});

it('is reachable by nobody but an admin', function () {
    $transaction = backfilled('INV-BACKFILL-GUARD-2');

    $payload = ['reason' => 'other', 'note' => 'Not yours to make.'];

    $this->postJson("/api/admin/transactions/{$transaction->id}/adjustments", $payload)
        ->assertUnauthorized();

    $this->actingAs($this->user, 'merchant')
        ->postJson("/api/admin/transactions/{$transaction->id}/adjustments", $payload)
        ->assertUnauthorized();

    $this->actingAs($this->customer, 'customer')
        ->postJson("/api/admin/transactions/{$transaction->id}/adjustments", $payload)
        ->assertUnauthorized();

    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and(Adjustment::query()->count())->toBe(0);
});

it('leaves the vendor and merchant paths refusing backdated rows exactly as before', function () {
    $transaction = backfilled('INV-BACKFILL-UNCHANGED');

    foreach ([Actor::merchantUser($this->user->id), Actor::pos(1), Actor::system(), Actor::customer($this->customer->id)] as $actor) {
        expect(fn () => app(ReversalService::class)->reverse(
            $transaction,
            $actor,
            'customer_refund',
            now()->toImmutable(),
        ))->toThrow(BackdatedIrreversibleException::class);
    }

    expect($transaction->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and(Adjustment::query()->count())->toBe(0);
});
