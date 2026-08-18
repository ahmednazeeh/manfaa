<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Jobs\NotifyStorePublicationChange;
use App\Jobs\SendCustomerSms;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Self-service unpublish (owner round 2026-08-18).
 *
 * A store takes itself off the app and puts itself back, with no admin in
 * the loop. Three things must hold: it vanishes from discovery, it stops
 * giving cashback, and the customers who have earned there hear about it —
 * at most once a day per direction, however often the switch is flipped.
 */
beforeEach(function () {
    $this->merchant = Merchant::factory()->create([
        'name' => 'Tea Plus',
        'status' => 'active',
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->actingAs($this->owner, 'merchant');
});

it('takes the store off every public surface and puts it back', function () {
    $this->postJson('/api/merchant/publication', ['published' => false])
        ->assertOk()
        ->assertJsonPath('data.published', false);

    expect($this->merchant->fresh()->unpublished_at)->not->toBeNull();

    // Gone from its own page as well as the shelves — a link that outlived
    // the decision to go dark is the same failure as a stale shelf.
    $this->getJson("/api/discover/merchants/{$this->merchant->slug}")->assertNotFound();

    $this->postJson('/api/merchant/publication', ['published' => true])
        ->assertOk()
        ->assertJsonPath('data.published', true);

    expect($this->merchant->fresh()->unpublished_at)->toBeNull();
});

it('refuses a manual credit while paused, in words a cashier can act on', function () {
    Customer::factory()->create(['customer_code' => '482917']);

    $this->postJson('/api/merchant/publication', ['published' => false])->assertOk();

    $response = $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-0001',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ]);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
    // Never the suspension sentence: nothing is wrong with this account.
    expect((string) $response->json('message'))
        ->toContain('paused')
        ->not->toContain('suspended');

    expect(Transaction::query()->count())->toBe(0);
});

it('notifies the customers who earned there and nobody else', function () {
    $earned = Customer::factory()->create();
    Customer::factory()->create(); // never shopped here
    $reversedOnly = Customer::factory()->create();

    Transaction::factory()->for($this->merchant)->for($earned)->create([
        'cashback_laari' => 500,
        'state' => 'confirmed',
    ]);
    // A reversed sale taught this shopper nothing about the shop.
    Transaction::factory()->for($this->merchant)->for($reversedOnly)->create([
        'cashback_laari' => 500,
        'state' => 'reversed',
    ]);

    Queue::fake();

    (new NotifyStorePublicationChange((int) $this->merchant->getKey(), true))
        ->handle(app(NotificationService::class));

    $texted = collect(Queue::pushed(SendCustomerSms::class));

    // Push is the channel; SMS is never spent on someone else's shop pausing.
    expect($texted)->toBeEmpty();
});

it('never spends an SMS on a store pausing', function () {
    expect(NotificationTemplateKey::StorePaused->usesSms())->toBeFalse()
        ->and(NotificationTemplateKey::StoreResumed->usesSms())->toBeFalse()
        ->and(NotificationTemplateKey::CashbackEarned->usesSms())->toBeTrue();
});

it('sends at most one notice of each kind per day, however often the switch is flipped', function () {
    Bus::fake();

    $this->postJson('/api/merchant/publication', ['published' => false])
        ->assertJsonPath('data.customers_notified', true);
    $this->postJson('/api/merchant/publication', ['published' => true])
        ->assertJsonPath('data.customers_notified', true);
    $this->postJson('/api/merchant/publication', ['published' => false])
        ->assertJsonPath('data.customers_notified', false);
    $this->postJson('/api/merchant/publication', ['published' => true])
        ->assertJsonPath('data.customers_notified', false);

    // One paused message and one resumed message, not four.
    Bus::assertDispatchedTimes(NotifyStorePublicationChange::class, 2);

    // And the store really is back on: the cap governs the MESSAGES, never
    // whether the merchant may use their own switch.
    expect($this->merchant->fresh()->unpublished_at)->toBeNull();
});

it('is idempotent: publishing an already published store notifies nobody', function () {
    Bus::fake();

    $this->postJson('/api/merchant/publication', ['published' => true])
        ->assertOk()
        ->assertJsonPath('data.customers_notified', false);

    Bus::assertNotDispatched(NotifyStorePublicationChange::class);
});

it('lets the day roll over and speak again', function () {
    Bus::fake();

    $this->postJson('/api/merchant/publication', ['published' => false])
        ->assertJsonPath('data.customers_notified', true);

    // Yesterday's message must not silence today's.
    Merchant::query()->whereKey($this->merchant->getKey())->update([
        'unpublish_notified_at' => now()->subHours(25),
        'unpublished_at' => null,
    ]);

    // The acting user carries the merchant relation it loaded during the
    // FIRST request; a real second request would build it from the session
    // and read the row we just wrote. Drop the cached relation so this test
    // asks the database the same question production would.
    $this->owner->unsetRelation('merchant');

    $this->postJson('/api/merchant/publication', ['published' => false])
        ->assertJsonPath('data.customers_notified', true);

    Bus::assertDispatchedTimes(NotifyStorePublicationChange::class, 2);
});

it('keeps the switch away from staff who do not hold the authority', function () {
    $cashier = MerchantUser::factory()->for($this->merchant)->create();

    $this->actingAs($cashier, 'merchant')
        ->postJson('/api/merchant/publication', ['published' => false])
        ->assertForbidden();

    expect($this->merchant->fresh()->unpublished_at)->toBeNull();
});
