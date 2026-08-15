<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
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
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A merchant that can take manual credits, with a standing 200bp rate. */
function queueMerchant(string $name): Merchant
{
    $merchant = Merchant::factory()->create([
        'name' => $name,
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    return $merchant;
}

function queueHold(
    Merchant $merchant,
    string $customerCode,
    string $invoiceNo,
    CarbonImmutable $heldAt,
    string $reason,
): Transaction {
    Carbon::setTestNow($heldAt);
    $user = MerchantUser::factory()->for($merchant)->owner()->create();

    $transaction = app(ManualCreditService::class)
        ->credit($merchant, $user, $customerCode, $invoiceNo, Laari::of(125_000), null, $heldAt->subHour());

    app(TransitionService::class)->hold($transaction->refresh(), Actor::admin(9_999), $reason);

    return $transaction->refresh();
}

it('lists holds oldest first with the store, masked customer, amounts, reason, age and origin', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:00:00+00:00');

    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $alpha = queueMerchant('Alpha Mart');

    $older = queueHold($alpha, '482917', 'INV-Q-1', $now->subDays(12), 'fraud_review');
    $newer = queueHold($alpha, '482917', 'INV-Q-2', $now->subDays(2), 'velocity_check');

    Carbon::setTestNow($now);

    $response = $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/holds')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        // Oldest review first: the one that has waited longest needs a
        // decision most.
        ->assertJsonPath('data.0.id', $older->id)
        ->assertJsonPath('data.1.id', $newer->id)
        ->assertJsonPath('data.0.merchant.name', 'Alpha Mart')
        // Enough of the customer to recognise them, and nothing else.
        ->assertJsonPath('data.0.customer.customer_code', '482917')
        ->assertJsonPath('data.0.customer.masked_name', 'Ais*** Moh***')
        ->assertJsonPath('data.0.invoice_no', 'INV-Q-1')
        ->assertJsonPath('data.0.origin', 'manual')
        ->assertJsonPath('data.0.eligible_laari', 125_000)
        ->assertJsonPath('data.0.cashback_laari', 2_500)
        ->assertJsonPath('data.0.fee_laari', 938)
        ->assertJsonPath('data.0.accrued_laari', 3_438)
        ->assertJsonPath('data.0.has_accrual', true)
        ->assertJsonPath('data.0.reason_code', 'fraud_review')
        ->assertJsonPath('data.0.age_days', 12)
        ->assertJsonPath('data.1.age_days', 2)
        ->assertJsonPath('data.0.pre_hold_state', 'awaiting_validation')
        // Both sales are past the 3-day window, so both releases start the
        // §7 clock — the queue says so before anybody clicks.
        ->assertJsonPath('data.0.release_target.state', 'payable_unfunded')
        ->assertJsonPath('data.0.release_target.starts_clock', true)
        // Neither row was on the clock when it was held, so both releases
        // START one rather than resuming a frozen one.
        ->assertJsonPath('data.0.release_target.resumes_clock', false);

    expect($response->json('summary.total'))->toBe(2)
        ->and($response->json('data.0.held_at'))->toBe($now->subDays(12)->toIso8601String());
});

it('shows a fresh hold releasing back to its pre-hold state without starting a clock', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:00:00+00:00');

    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $merchant = queueMerchant('Beta Store');
    queueHold($merchant, '482917', 'INV-Q-3', $now, 'fraud_review');

    Carbon::setTestNow($now);

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/holds')
        ->assertOk()
        ->assertJsonPath('data.0.release_target.state', 'awaiting_validation')
        ->assertJsonPath('data.0.release_target.starts_clock', false)
        ->assertJsonPath('data.0.release_target.resumes_clock', false)
        ->assertJsonPath('data.0.age_days', 0);
});

it('says a hold placed on a row that was already on the clock RESUMES it, not starts it', function () {
    // The dialog promises the consequence in words before the admin confirms,
    // and "the 15-day clock starts now" is a different promise from "the
    // clock resumes where the hold froze it" — a row held while overdue comes
    // back overdue. Both come from the server so the two can never drift.
    $now = CarbonImmutable::parse('2026-08-20T10:00:00+00:00');

    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $merchant = queueMerchant('Gamma Traders');
    $user = MerchantUser::factory()->for($merchant)->owner()->create();

    Carbon::setTestNow($now->subDays(40));
    $transaction = app(ManualCreditService::class)
        ->credit($merchant, $user, '482917', 'INV-Q-CLOCK', Laari::of(125_000), null, $now->subDays(40)->subHour());
    app(TransitionService::class)->makePayable($transaction->refresh(), Actor::system());

    Carbon::setTestNow($now->subDays(15));
    app(TransitionService::class)->hold($transaction->refresh(), Actor::admin(9_999), 'fraud_review');

    Carbon::setTestNow($now);

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/holds')
        ->assertOk()
        ->assertJsonPath('data.0.pre_hold_state', 'payable_unfunded')
        ->assertJsonPath('data.0.release_target.state', 'payable_unfunded')
        ->assertJsonPath('data.0.release_target.starts_clock', true)
        ->assertJsonPath('data.0.release_target.resumes_clock', true);
});

it('filters by reason and by merchant while the summary keeps counting every hold', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:00:00+00:00');

    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $alpha = queueMerchant('Alpha Mart');
    $beta = queueMerchant('Beta Store');

    $fraud = queueHold($alpha, '482917', 'INV-Q-4', $now->subDays(9), 'fraud_review');
    queueHold($alpha, '482917', 'INV-Q-5', $now->subDays(8), 'velocity_check');
    $betaHold = queueHold($beta, '482917', 'INV-Q-6', $now->subDays(7), 'fraud_review');

    Carbon::setTestNow($now);

    $byReason = $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/holds?reason=fraud_review')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $fraud->id)
        ->assertJsonPath('data.1.id', $betaHold->id);

    // The badge and the pickers must stay truthful under a filter.
    expect($byReason->json('summary.total'))->toBe(3)
        ->and($byReason->json('meta.total'))->toBe(2)
        ->and(collect($byReason->json('summary.reasons'))->pluck('count', 'reason_code')->all())
        ->toBe(['fraud_review' => 2, 'velocity_check' => 1])
        ->and(collect($byReason->json('summary.merchants'))->pluck('count', 'name')->all())
        ->toBe(['Alpha Mart' => 2, 'Beta Store' => 1]);

    $this->actingAs($this->admin, 'admin')
        ->getJson("/api/admin/holds?merchant_id={$beta->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $betaHold->id);

    $this->actingAs($this->admin, 'admin')
        ->getJson("/api/admin/holds?merchant_id={$beta->id}&reason=velocity_check")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lists only held rows, and reports the hold reason from history after the row has moved on', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:00:00+00:00');

    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $merchant = queueMerchant('Gamma Goods');

    $held = queueHold($merchant, '482917', 'INV-Q-7', $now->subDays(4), 'stale_timestamp');
    $released = queueHold($merchant, '482917', 'INV-Q-8', $now->subDays(4), 'fraud_review');

    Carbon::setTestNow($now);
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$released->id}/release")
        ->assertOk();

    // The released row's reason_code column now reads admin_release; the queue
    // must not confuse that with a hold reason, and must not list it at all.
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/holds')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $held->id)
        // Legacy staleness holds are still real production rows.
        ->assertJsonPath('data.0.reason_code', 'stale_timestamp')
        ->assertJsonPath('summary.total', 1);
});

it('is admin-only', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:00:00+00:00');

    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $merchant = queueMerchant('Delta Depot');
    $transaction = queueHold($merchant, '482917', 'INV-Q-9', $now->subDays(4), 'fraud_review');
    $merchantUser = MerchantUser::factory()->for($merchant)->owner()->create();
    $customer = Customer::query()->firstOrFail();

    Carbon::setTestNow($now);

    // A merchant must not learn which of their sales is under fraud review,
    // and certainly must not be able to clear it.
    $this->getJson('/api/admin/holds')->assertUnauthorized();
    $this->postJson("/api/admin/holds/{$transaction->id}/release")->assertUnauthorized();
    $this->postJson("/api/admin/holds/{$transaction->id}/reject", ['reason' => 'no'])->assertUnauthorized();

    $this->actingAs($merchantUser, 'merchant')->getJson('/api/admin/holds')->assertUnauthorized();
    $this->actingAs($merchantUser, 'merchant')
        ->postJson("/api/admin/holds/{$transaction->id}/release")
        ->assertUnauthorized();
    $this->actingAs($customer, 'customer')->getJson('/api/admin/holds')->assertUnauthorized();

    expect($transaction->refresh()->state->value)->toBe('on_hold');
});
