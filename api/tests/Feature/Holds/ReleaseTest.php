<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\HoldReviewService;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Standing\SuspensionService;
use App\Domain\Standing\ValidationSweeper;
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
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * One credit through the REAL accrual path, then held — so the ledger, the
 * transactions table and the event history all agree, exactly as they do in
 * production. $occurredAt drives which side of the validation window the sale
 * sits on, which is the whole subject of these tests.
 */
function heldSale(
    Merchant $merchant,
    MerchantUser $user,
    string $invoiceNo,
    CarbonImmutable $occurredAt,
    string $holdReason = 'fraud_review',
): Transaction {
    $transaction = app(ManualCreditService::class)
        ->credit($merchant, $user, '482917', $invoiceNo, Laari::of(125_000), null, $occurredAt);

    app(TransitionService::class)->hold($transaction->refresh(), Actor::admin(9_999), $holdReason);

    return $transaction->refresh();
}

it('releases an elapsed-window hold to payable_unfunded WITH the clock stamped in the same call', function () {
    // The regression PLAN §13b names: an earlier manual release left the row
    // payable with clock_start_at and due_at null, so the §7 escalation
    // ladder, the day-16 suspension and the 90-day write-off all skipped it —
    // the merchant owed money on a clock that was never running.
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');

    // Five days old: past the 3-day validation window, inside the 6-day
    // backdating threshold — a plain sale whose refund window has closed.
    Carbon::setTestNow($now->subDays(5));
    $transaction = heldSale($this->merchant, $this->user, 'INV-REL-1', $now->subDays(5)->subHour());

    expect($transaction->state)->toBe(TransactionState::OnHold)
        ->and($transaction->clock_start_at)->toBeNull()
        ->and($transaction->due_at)->toBeNull();

    Carbon::setTestNow($now);
    $eventsBefore = $transaction->events()->count();

    $response = $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release", ['note' => 'Reviewed the till roll — genuine sale.'])
        ->assertOk();

    $transaction->refresh();

    expect($transaction->state)->toBe(TransactionState::PayableUnfunded)
        // Both stamped, and stamped to THIS release — not inherited, not null.
        ->and($transaction->clock_start_at?->getTimestamp())->toBe($now->getTimestamp())
        ->and($transaction->due_at?->getTimestamp())->toBe($now->addDays(15)->getTimestamp())
        ->and($transaction->reason_code)->toBe(HoldReviewService::RELEASE_REASON)
        // One hop, one event: the state and the clock cannot land apart.
        ->and($transaction->events()->count())->toBe($eventsBefore + 1);

    $response->assertJsonPath('data.state', 'payable_unfunded')
        ->assertJsonPath('data.clock_start_at', $now->toIso8601String())
        ->assertJsonPath('data.due_at', $now->addDays(15)->toIso8601String());
});

it('records the releasing admin and the note on the release event', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now->subDays(5));
    $transaction = heldSale($this->merchant, $this->user, 'INV-REL-2', $now->subDays(5)->subHour(), 'velocity_check');

    Carbon::setTestNow($now);
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release", ['note' => 'Store confirmed the customer in person.'])
        ->assertOk();

    $event = $transaction->events()->orderByDesc('id')->first();

    expect($event->from_state)->toBe('on_hold')
        ->and($event->to_state)->toBe('payable_unfunded')
        ->and($event->actor_type)->toBe('admin')
        ->and($event->actor_id)->toBe($this->admin->id)
        ->and($event->reason_code)->toBe('admin_release')
        ->and($event->meta['note'])->toBe('Store confirmed the customer in person.')
        // The hold's own reason survives on the release event even though the
        // row's reason_code has moved on.
        ->and($event->meta['hold_reason_code'])->toBe('velocity_check')
        ->and($event->meta['validation_window_elapsed'])->toBeTrue()
        ->and($event->meta['clock_start_at'])->toBe($now->toIso8601String());
});

it('releases a fresh hold back to the pre-hold state read from the event history', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    // Inside the 3-day window: nothing is payable yet, so the release must
    // put the row back where it came from and start no clock.
    $fromAwaiting = heldSale($this->merchant, $this->user, 'INV-REL-3', $now->subHour());

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$fromAwaiting->id}/release")
        ->assertOk()
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.clock_start_at', null)
        ->assertJsonPath('data.due_at', null);

    expect($fromAwaiting->refresh()->state)->toBe(TransactionState::AwaitingValidation)
        ->and($fromAwaiting->clock_start_at)->toBeNull()
        ->and($fromAwaiting->events()->orderByDesc('id')->first()->reason_code)->toBe('admin_release');
});

it('never releases a hold back to tracked — the row would be stranded there forever', function () {
    // Every legacy `stale_timestamp` hold in production was placed straight
    // out of `tracked` (the pre-task-23 CreditRecorder held on creation), and
    // widening the store's validation window afterwards puts such a row back
    // INSIDE its window, so the release is no longer payable. Handing the
    // history's `tracked` back would strand it: nothing sweeps tracked, so it
    // would sit in customer-facing Pending forever — never payable, never
    // billed, never written off, and off this queue and the stale-hold report
    // the moment it left on_hold.
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $legacyStaleHold = Transaction::factory()->for($this->merchant)->create([
        'state' => 'tracked',
        'occurred_at' => $now->subDays(10),
        'received_at' => $now->subDays(10),
        'reason_code' => 'stale_timestamp',
    ]);
    app(TransitionService::class)->hold($legacyStaleHold, Actor::system(), 'stale_timestamp');

    // The store's refund window is widened after the fact: 10 days old is now
    // inside a 30-day window, so the release cannot go straight to payable.
    $this->merchant->update(['validation_window_days' => 30]);

    // The queue promises the same landing state the release will produce.
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/holds')
        ->assertOk()
        ->assertJsonPath('data.0.pre_hold_state', 'tracked')
        ->assertJsonPath('data.0.release_target.state', 'awaiting_validation')
        ->assertJsonPath('data.0.release_target.starts_clock', false);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$legacyStaleHold->id}/release")
        ->assertOk()
        ->assertJsonPath('data.state', 'awaiting_validation');

    expect($legacyStaleHold->refresh()->state)->toBe(TransactionState::AwaitingValidation);

    // And it is genuinely back on the road: the §7 sweeper picks it up on its
    // own once the widened window closes.
    Carbon::setTestNow($now->addDays(21));

    expect(app(ValidationSweeper::class)->run())->toBe(1)
        ->and($legacyStaleHold->refresh()->state)->toBe(TransactionState::PayableUnfunded)
        ->and($legacyStaleHold->clock_start_at)->not->toBeNull()
        ->and($legacyStaleHold->due_at)->not->toBeNull();
});

it('resumes the clock when a row that was already payable is released, crediting only the frozen time', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');

    Carbon::setTestNow($now->subDays(20));
    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-REL-4', Laari::of(125_000), null, $now->subDays(20)->subHour());

    // On the clock, then frozen by a 20-day fraud review that opened the same
    // instant the clock started — so the whole 15 days were unsettleable.
    app(TransitionService::class)->makePayable($transaction->refresh(), Actor::system());
    $originalDueAt = $transaction->refresh()->due_at;
    app(TransitionService::class)->hold($transaction, Actor::admin(9_999), 'fraud_review');

    expect($originalDueAt->isBefore($now))->toBeTrue();

    Carbon::setTestNow($now);
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release", ['note' => 'Cleared.'])
        ->assertOk();

    $transaction->refresh();
    $event = $transaction->events()->orderByDesc('id')->first();

    // The hold froze the row — the merchant could not have settled it — so the
    // review period is not charged to them: the clock advances by exactly the
    // 20 days it was frozen, which here restores the full 15-day window
    // because the freeze began on day 0. The evidence records the shift.
    expect($transaction->state)->toBe(TransactionState::PayableUnfunded)
        ->and($transaction->clock_start_at?->getTimestamp())->toBe($now->getTimestamp())
        ->and($transaction->due_at?->getTimestamp())->toBe($now->addDays(15)->getTimestamp())
        ->and($event->meta['clock_resumed'])->toBeTrue()
        ->and($event->meta['clock_frozen_seconds'])->toBe(20 * 86_400);
});

it('keeps a merchant overdue when a hold placed on an already-overdue row is released', function () {
    // The §7 day-16 suspension is the platform's ONLY credit control, and the
    // 30-minute reinstatement sweep reverses it the moment nothing is overdue.
    // A release that re-stamped a fresh 15-day clock would therefore un-suspend
    // a defaulting store that never paid — two admin clicks and the debt is
    // current again. The frozen interval is credited; the elapsed position is
    // not given back.
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');

    Carbon::setTestNow($now->subDays(40));
    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-REL-7', Laari::of(125_000), null, $now->subDays(40)->subHour());
    app(TransitionService::class)->makePayable($transaction->refresh(), Actor::system());
    $originalDueAt = $transaction->refresh()->due_at;

    // Due on day 15 (now - 25d) and still unpaid: overdue for 10 days by the
    // time a fraud review freezes it at now - 15d.
    Carbon::setTestNow($now->subDays(15));
    expect($originalDueAt->isBefore(CarbonImmutable::now()))->toBeTrue();
    app(TransitionService::class)->hold($transaction->refresh(), Actor::admin(9_999), 'fraud_review');

    Carbon::setTestNow($now);
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release", ['note' => 'Genuine sale — still owed.'])
        ->assertOk()
        ->assertJsonPath('data.due_at', $originalDueAt->addDays(15)->toIso8601String());

    $transaction->refresh();

    // Old due date + the 15-day freeze: still 10 days overdue, exactly as it
    // was when the review opened, and never a date in the future.
    expect($transaction->due_at?->getTimestamp())->toBe($originalDueAt->addDays(15)->getTimestamp())
        ->and($transaction->due_at?->isBefore($now))->toBeTrue()
        ->and($transaction->clock_start_at?->isBefore($now))->toBeTrue()
        ->and($transaction->events()->orderByDesc('id')->first()->meta['clock_frozen_seconds'])->toBe(15 * 86_400);

    // The credit control still sees the debt, and the reinstatement sweep
    // does not hand the store back its licence to accrue.
    app(SuspensionService::class)->suspendOverdue();
    expect($this->merchant->refresh()->status)->toBe('suspended');

    app(SuspensionService::class)->reinstate();
    expect($this->merchant->refresh()->status)->toBe('suspended');
});

it('refuses to release a transaction that is not on hold, with 409 not_on_hold', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-REL-5', Laari::of(125_000), null, $now->subHour());

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release")
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_on_hold');

    expect($transaction->refresh()->state)->toBe(TransactionState::AwaitingValidation);
});

it('rejects a release note that is present but empty', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now->subDays(5));
    $transaction = heldSale($this->merchant, $this->user, 'INV-REL-6', $now->subDays(5)->subHour());

    Carbon::setTestNow($now);
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release", ['note' => 'x'])
        ->assertStatus(422);

    expect($transaction->refresh()->state)->toBe(TransactionState::OnHold);
});
