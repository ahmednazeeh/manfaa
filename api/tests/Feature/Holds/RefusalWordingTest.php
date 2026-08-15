<?php

declare(strict_types=1);

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Money\Laari;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN §13b task #22 — no raw snake_case anywhere in a UI, the hold queue
 * included.
 *
 * The queue's two refusals are the one place a §6 state key could still reach
 * an operator's screen after the label pass: the panel branches on the 409
 * `code`, but its fallback renders the 409 `message` verbatim in a toast. So
 * the contract is asserted on both halves at once — the code stays machine
 * readable for the branch, and the message stays free of machine vocabulary
 * for the human.
 */
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
    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Every §6 state key, the vocabulary a refusal message may not use. */
function stateKeys(): array
{
    return array_map(fn (TransactionState $state): string => $state->value, TransactionState::cases());
}

it('refuses a release on a row that moved on without printing the state key', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    // Never held: awaiting_validation, exactly what the second admin to click
    // Release on a stale queue page hits.
    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-WORD-1', Laari::of(125_000), null, $now->subHour());

    $response = $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/release")
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_on_hold');

    $message = $response->json('message');

    // The state is named — an operator has to know why it refused — but in
    // words, not in the key the state machine uses.
    expect($message)->toContain('awaiting validation');

    foreach (stateKeys() as $key) {
        expect($message)->not->toContain($key);
    }
});

it('refuses a reject on a row that moved on without printing the state key', function () {
    $now = CarbonImmutable::parse('2026-08-20T10:30:00+00:00');
    Carbon::setTestNow($now);

    $transaction = app(ManualCreditService::class)
        ->credit($this->merchant, $this->user, '482917', 'INV-WORD-2', Laari::of(125_000), null, $now->subHour());

    $response = $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/holds/{$transaction->id}/reject", [
            'reason' => 'Duplicate of an earlier sale from the same till.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_on_hold');

    $message = $response->json('message');

    foreach (stateKeys() as $key) {
        expect($message)->not->toContain($key);
    }

    // Refused before anything ran: the sale is untouched.
    expect($transaction->refresh()->state)->toBe(TransactionState::AwaitingValidation);
});
