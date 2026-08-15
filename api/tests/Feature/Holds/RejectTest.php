<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\LedgerJournal;
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
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Journals whose per-currency debits and credits differ — always empty. */
function rejectUnbalancedJournals(): array
{
    return DB::select(<<<'SQL'
        SELECT journal_id, currency, SUM(debit_laari) - SUM(credit_laari) AS net
        FROM ledger_entries
        GROUP BY journal_id, currency
        HAVING SUM(debit_laari) <> SUM(credit_laari)
        SQL);
}

function heldSaleForReject(Merchant $merchant, MerchantUser $user, string $invoiceNo, CarbonImmutable $occurredAt): Transaction
{
    $transaction = app(ManualCreditService::class)
        ->credit($merchant, $user, '482917', $invoiceNo, Laari::of(125_000), null, $occurredAt);

    app(TransitionService::class)->hold($transaction->refresh(), Actor::admin(9_999), 'fraud_review');

    return $transaction->refresh();
}

it('reverses a rejected hold and mirrors the accrual with a balanced journal', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = heldSaleForReject($this->merchant, $this->user, 'INV-REJ-1', $now->subHour());

    // §4 on 125000 @200bp/75bp — the STORED integers a reversal must mirror.
    expect($transaction->cashback_laari)->toBe(2_500)
        ->and($transaction->fee_laari)->toBe(938)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2_500)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(3_438);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", [
            'reason' => 'Three identical invoices from one till in four minutes; the store could not produce the sales.',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'reversed')
        ->assertJsonPath('data.reason_code', 'admin_reject');

    $transaction->refresh();

    // The mirror nets every account the accrual touched back to zero, and
    // both journals balance on their own.
    expect($transaction->state)->toBe(TransactionState::Reversed)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe(0)
        ->and(rejectUnbalancedJournals())->toBe([])
        ->and($this->balances->journalsAllBalance())->toBeTrue()
        // Exactly two journals against this transaction: the accrual and its
        // mirror. Nothing was recomputed and nothing was posted twice.
        ->and(LedgerJournal::query()->where('reference_type', 'transaction')->where('reference_id', $transaction->id)->count())
        ->toBe(2);
});

it('records the admin and the required reason on the reversal event', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = heldSaleForReject($this->merchant, $this->user, 'INV-REJ-2', $now->subHour());

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", ['reason' => 'Duplicate of INV-9001, confirmed with the store.'])
        ->assertOk();

    $event = $transaction->events()->orderByDesc('id')->first();

    expect($event->from_state)->toBe('on_hold')
        ->and($event->to_state)->toBe('reversed')
        ->and($event->actor_type)->toBe('admin')
        ->and($event->actor_id)->toBe($this->admin->id)
        // The machine qualifier is a stable code; the admin's words are the
        // note. Free text must never land in a reason_code the apps translate.
        ->and($event->reason_code)->toBe('admin_reject')
        ->and($event->meta['note'])->toBe('Duplicate of INV-9001, confirmed with the store.')
        ->and($transaction->refresh()->reason_code)->toBe('admin_reject');
});

it('requires a reason to reject', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = heldSaleForReject($this->merchant, $this->user, 'INV-REJ-3', $now->subHour());

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($transaction->refresh()->state)->toBe(TransactionState::OnHold);
});

it('refuses to reject a confirmed transaction with 409 and touches neither state nor ledger', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-REJ-4', Laari::of(125_000), null, $now->subHour());

    $transitions = app(TransitionService::class);
    $transitions->makePayable($transaction->refresh(), Actor::system());
    $transitions->confirm($transaction->refresh(), Actor::system());

    $journalsBefore = LedgerJournal::query()->count();

    // §6: corrections after confirmation are adjustments, never reversals —
    // and a confirmed row is not on_hold, so the queue refuses it outright.
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", ['reason' => 'Customer disputed the sale.'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_on_hold');

    expect($transaction->refresh()->state)->toBe(TransactionState::Confirmed)
        ->and(LedgerJournal::query()->count())->toBe($journalsBefore);
});

it('refuses to reject a paid transaction with 409', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-REJ-5', Laari::of(125_000), null, $now->subHour());

    $transitions = app(TransitionService::class);
    $transitions->makePayable($transaction->refresh(), Actor::system());
    $transitions->confirm($transaction->refresh(), Actor::system());
    $transitions->markPaid($transaction->refresh(), Actor::system());

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", ['reason' => 'Too late.'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_on_hold');

    expect($transaction->refresh()->state)->toBe(TransactionState::Paid);
});

it('reverses a zeroed hold without posting anything — there is no accrual to mirror', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    // A row whose §4 pricing rounded everything to zero never posted an
    // accrual, so its reversal must post no mirror either. (Live zeroed rows
    // close themselves at creation; production still holds legacy ones.)
    $transaction = Transaction::factory()->for($this->merchant)->create([
        'state' => 'tracked',
        'cashback_laari' => 0,
        'fee_laari' => 0,
        'fee_gst_laari' => 0,
        'occurred_at' => $now->subHour(),
        'received_at' => $now->subHour(),
    ]);
    app(TransitionService::class)->hold($transaction, Actor::admin(9_999), 'fraud_review');

    $journalsBefore = LedgerJournal::query()->count();

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", ['reason' => 'Test card sale, no goods.'])
        ->assertOk()
        ->assertJsonPath('data.state', 'reversed');

    expect($transaction->refresh()->state)->toBe(TransactionState::Reversed)
        ->and(LedgerJournal::query()->count())->toBe($journalsBefore)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});
