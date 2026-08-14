<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Domain\Webhooks\WebhookEvents;
use App\Jobs\SendWebhook;
use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\PosVendor;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A vendor endpoint subscribed to $events, with a live credential for
 * $merchant.
 *
 * @param  list<string>  $events
 */
function whEmissionEndpoint(Merchant $merchant, array $events): WebhookEndpoint
{
    $vendor = PosVendor::query()->create(['name' => 'Vendor '.Str::random(6)]);

    ApiCredential::query()->create([
        'merchant_id' => $merchant->id,
        'pos_vendor_id' => $vendor->id,
        'token_hash' => hash('sha256', Str::random(40)),
        'abilities' => ['transactions:write', 'transactions:reverse'],
    ]);

    return WebhookEndpoint::query()->create([
        'pos_vendor_id' => $vendor->id,
        'url' => 'https://vendor.example/hooks/'.Str::random(8),
        'secret' => 'whsec_'.Str::random(48),
        'events' => $events,
        'active' => true,
    ]);
}

it('queues merchant.suspended when manfaa:suspend-overdue suspends a merchant', function () {
    Queue::fake();

    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();

    $merchant = Merchant::factory()->create();
    $endpoint = whEmissionEndpoint($merchant, [WebhookEvents::MERCHANT_SUSPENDED, WebhookEvents::MERCHANT_REINSTATED]);

    // A second vendor without any credential for this merchant hears nothing.
    $strangerVendor = PosVendor::query()->create(['name' => 'Stranger']);
    $strangerEndpoint = WebhookEndpoint::query()->create([
        'pos_vendor_id' => $strangerVendor->id,
        'url' => 'https://stranger.example/hooks',
        'secret' => 'whsec_'.Str::random(48),
        'events' => [WebhookEvents::MERCHANT_SUSPENDED],
        'active' => true,
    ]);

    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
    ]);

    Carbon::setTestNow($clockStart->addDays(16));
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('suspended');

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery->event)->toBe('merchant.suspended')
        ->and($delivery->payload['data']['merchant_id'])->toBe($merchant->id)
        ->and($delivery->payload['data']['reason'])->toBe('overdue_settlement')
        ->and($delivery->payload['data']['suspended_at'])->not->toBeNull();

    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $strangerEndpoint->id)->count())->toBe(0);

    Queue::assertPushed(SendWebhook::class, 1);
});

it('queues merchant.reinstated on the automatic reinstatement sweep', function () {
    Queue::fake();

    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();

    $merchant = Merchant::factory()->create();
    whEmissionEndpoint($merchant, [WebhookEvents::MERCHANT_SUSPENDED, WebhookEvents::MERCHANT_REINSTATED]);

    $overdue = Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
    ]);

    Carbon::setTestNow($clockStart->addDays(16));
    $this->artisan('manfaa:suspend-overdue')->assertExitCode(0);
    expect($merchant->refresh()->status)->toBe('suspended');

    // Settlement clears the debt; the next sweep reinstates and notifies.
    app(TransitionService::class)->confirm($overdue, Actor::system());
    $this->artisan('manfaa:reinstate')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('active');

    $reinstated = WebhookDelivery::query()->where('event', 'merchant.reinstated')->sole();
    expect($reinstated->payload['data']['merchant_id'])->toBe($merchant->id)
        ->and($reinstated->payload['data']['reinstated_at'])->not->toBeNull();

    // One suspended + one reinstated delivery, one job each.
    Queue::assertPushed(SendWebhook::class, 2);
});

it('queues merchant.reinstated on a manual admin reinstatement', function () {
    Queue::fake();

    $merchant = Merchant::factory()->suspended()->create();
    whEmissionEndpoint($merchant, [WebhookEvents::MERCHANT_REINSTATED]);

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'Paid out of band.'])
        ->assertOk();

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->event)->toBe('merchant.reinstated')
        ->and($delivery->payload['data']['merchant_id'])->toBe($merchant->id);

    Queue::assertPushed(SendWebhook::class, 1);
});

it('queues transaction.reversed when a vendor reversal actually reverses the transaction', function () {
    Queue::fake();
    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create();
    whEmissionEndpoint($merchant, [WebhookEvents::TRANSACTION_REVERSED]);

    $transaction = Transaction::factory()->for($merchant)->create(['state' => 'tracked']);

    $token = $merchant->createToken('till', ['transactions:reverse'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson("/api/v1/transactions/{$transaction->id}/reverse", [
        'reason' => 'customer_refund',
        'occurred_at' => now()->toIso8601String(),
    ])->assertOk()->assertJsonPath('outcome', 'reversed');

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->event)->toBe('transaction.reversed')
        ->and($delivery->payload['data']['transaction_id'])->toBe($transaction->id)
        ->and($delivery->payload['data']['merchant_id'])->toBe($merchant->id)
        ->and($delivery->payload['data']['invoice_no'])->toBe($transaction->invoice_no)
        ->and($delivery->payload['data']['reason'])->toBe('customer_refund')
        ->and($delivery->payload['data']['reversed_at'])->not->toBeNull();

    Queue::assertPushed(SendWebhook::class, 1);
});

it('emits nothing when the reversal becomes a credit adjustment instead', function () {
    Queue::fake();
    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create();
    whEmissionEndpoint($merchant, [WebhookEvents::TRANSACTION_REVERSED]);

    // Already confirmed: §7 says the reversal becomes an adjustment; the
    // transaction never transitions to reversed, so no event goes out.
    $transaction = Transaction::factory()->for($merchant)->create([
        'state' => 'confirmed',
        'confirmed_at' => now()->subDay(),
    ]);

    $token = $merchant->createToken('till', ['transactions:reverse'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson("/api/v1/transactions/{$transaction->id}/reverse", [
        'reason' => 'customer_refund',
        'occurred_at' => now()->toIso8601String(),
    ])->assertOk()->assertJsonPath('outcome', 'adjustment_created');

    expect(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
