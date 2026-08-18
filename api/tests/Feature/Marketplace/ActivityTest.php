<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Platform\PlatformConfig;
use App\Jobs\SendCustomerSms;
use App\Jobs\SendPushNotification;
use App\Models\BranchDeliveryRule;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeviceToken;
use App\Models\MerchantUser;
use App\Models\NotificationTemplate;
use App\Models\Suborder;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/fixtures.php';

/**
 * MP7 — one timeline, and the messages that reach a customer who is not
 * looking at it (`Customer App Order Tracking.png`, §8).
 */
beforeEach(function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    $this->zone = marketZone();
    $this->customer = Customer::factory()->create();
    CustomerAddress::factory()->for($this->customer)->create([
        'zone_id' => $this->zone->id, 'is_default' => true,
    ]);

    $this->vendor = vendor('Island Mart', rateBp: 200, priceLaari: 10000);
    BranchDeliveryRule::factory()->create([
        'branch_id' => $this->vendor['branch']->id,
        'zone_id' => $this->zone->id,
        'delivery_fee_laari' => 2500,
    ]);
    $this->shopkeeper = MerchantUser::factory()->for($this->vendor['merchant'])->owner()->create();
});

function anOrder($test, int $qty = 2): Suborder
{
    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/cart/items', [
            'branch_product_id' => $test->vendor['listing']->id, 'qty' => $qty,
        ])->assertOk();

    $test->actingAs($test->customer, 'customer')
        ->postJson('/api/customer/orders', ['payment_method' => 'bml'])->assertCreated();

    return Suborder::query()->latest('id')->firstOrFail();
}

it('merges marketplace orders and cashback into one timeline', function () {
    anOrder($this);

    // A till sale from the other half of the platform.
    Transaction::factory()->create([
        'customer_id' => $this->customer->id,
        'merchant_id' => $this->vendor['merchant']->id,
        'occurred_at' => now()->subDay(),
        'state' => 'confirmed',
        'cashback_laari' => 500,
    ]);

    $feed = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/activity')
        ->assertOk()->json();

    // "Track your marketplace and cashback orders in one place."
    expect($feed['data'])->toHaveCount(2)
        ->and($feed['meta']['total'])->toBe(2);

    $kinds = array_column($feed['data'], 'kind');
    expect($kinds)->toContain('order')->toContain('transaction')
        // Newest first: the order was placed after the till sale.
        ->and($kinds[0])->toBe('order');
});

it('shows a multi-vendor order by its shops, not one summary word', function () {
    $sub = anOrder($this);
    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    $card = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/activity?tab=active')
        ->assertOk()->json('data.0.order');

    // In a multi-vendor order the shops ARE the status — one word would hide
    // that two are confirmed and one is not.
    expect($card['stores'])->toHaveCount(1)
        ->and($card['stores'][0]['state'])->toBe('accepted')
        ->and($card['stores'][0]['store_name'])->toBe('Island Mart');
});

it('separates active, completed and cancelled on both sides', function () {
    anOrder($this);

    Transaction::factory()->create([
        'customer_id' => $this->customer->id,
        'merchant_id' => $this->vendor['merchant']->id,
        'occurred_at' => now()->subDay(),
        'state' => 'paid',
        'cashback_laari' => 500,
    ]);
    Transaction::factory()->create([
        'customer_id' => $this->customer->id,
        'merchant_id' => $this->vendor['merchant']->id,
        'occurred_at' => now()->subDays(2),
        'state' => 'reversed',
        'cashback_laari' => 0,
    ]);

    $count = fn (string $tab): int => count(
        $this->actingAs($this->customer, 'customer')
            ->getJson("/api/customer/activity?tab={$tab}")->json('data'),
    );

    expect($count('active'))->toBe(1)
        ->and($count('completed'))->toBe(1)
        ->and($count('cancelled'))->toBe(1);
});

it('pages the merged timeline without losing either source', function () {
    // The reason this is a SQL union: paging over an in-memory merge drops
    // whichever source is denser, so a heavy till shopper would stop seeing
    // their orders.
    anOrder($this);

    for ($i = 0; $i < 5; $i++) {
        Transaction::factory()->create([
            'customer_id' => $this->customer->id,
            'merchant_id' => $this->vendor['merchant']->id,
            'occurred_at' => now()->subDays($i + 1),
            'state' => 'paid',
            'cashback_laari' => 100,
        ]);
    }

    $first = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/activity?per_page=2')->assertOk()->json();

    expect($first['data'])->toHaveCount(2)
        ->and($first['meta']['total'])->toBe(6)
        ->and($first['meta']['last_page'])->toBe(3);

    $last = $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/activity?per_page=2&page=3')->assertOk()->json();

    expect($last['data'])->toHaveCount(2);
});

it('keeps one customer out of another\'s timeline', function () {
    anOrder($this);

    $stranger = Customer::factory()->create();

    expect($this->actingAs($stranger, 'customer')
        ->getJson('/api/customer/activity')->json('data'))->toHaveCount(0);
});

// --------------------------------------------------------- notifications

it('tells the customer when a shop accepts, and does not spend a text on it', function () {
    // A push needs somewhere to land. Without a device the platform sends
    // nothing, which is right — and is why this test issues one.
    $token = app(MobileTokenService::class)
        ->issue($this->customer, MobileAudience::Customer, 'Phone')
        ->plainTextToken;
    DeviceToken::query()->create([
        'tokenable_type' => $this->customer->getMorphClass(),
        'tokenable_id' => $this->customer->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($token)->getKey(),
        'token' => 'phone-'.Str::random(8),
        'platform' => 'android',
        'locale' => 'en',
    ]);

    Queue::fake();
    $sub = anOrder($this);

    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    // Good news they are probably already holding a phone for: push only.
    Queue::assertPushed(SendPushNotification::class);
    Queue::assertNotPushed(SendCustomerSms::class);
});

it('texts the customer for the two moments that cost them money', function () {
    Queue::fake();
    $sub = anOrder($this, qty: 3);

    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
            'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 1]],
            'reason' => 'out_of_stock',
        ])->assertOk();

    // A cut order costs them goods they paid for. They must not miss it.
    Queue::assertPushed(SendCustomerSms::class);
});

it('names the refund in the message about a cut order', function () {
    $sub = anOrder($this, qty: 3);
    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    Queue::fake();

    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/amend", [
            'lines' => [['suborder_item_id' => $sub->items()->sole()->id, 'fulfilled_qty' => 1]],
            'reason' => 'out_of_stock',
        ])->assertOk();

    $texts = collect(Queue::pushed(SendCustomerSms::class));
    $body = (new ReflectionProperty(SendCustomerSms::class, 'body'))->getValue($texts->first());

    // The money is the point of the message.
    expect($body)->toContain('MVR 200.00')->toContain('Island Mart');
});

it('sends nothing at all to a customer with no device and no number', function () {
    // Both channels decide for themselves whether they have somewhere to
    // deliver. Neither is an error.
    $this->customer->forceFill(['phone' => ''])->save();

    Queue::fake();
    $sub = anOrder($this);

    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    Queue::assertNotPushed(SendPushNotification::class);
    Queue::assertNotPushed(SendCustomerSms::class);
});

it('does not interrupt a phone for the shop\'s own bookkeeping', function () {
    $sub = anOrder($this);
    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/accept")->assertOk();

    Queue::fake();

    // "Preparing" is not news to a customer, and interrupting for it is how
    // people learn to ignore us.
    $this->actingAs($this->shopkeeper, 'merchant')
        ->postJson("/api/merchant/marketplace/orders/{$sub->id}/advance", ['state' => 'preparing'])
        ->assertOk();

    Queue::assertNotPushed(SendPushNotification::class);
    Queue::assertNotPushed(SendCustomerSms::class);
});

it('seeds a template and a Dhivehi push title for every order moment', function () {
    foreach (NotificationTemplateKey::cases() as $key) {
        if (! str_starts_with($key->value, 'order_')) {
            continue;
        }

        expect(NotificationTemplate::query()->where('key', $key->value)->exists())
            ->toBeTrue("no seeded row for {$key->value}");
        expect(trim($key->pushTitle()['dv']))->not->toBe('');
    }
});
