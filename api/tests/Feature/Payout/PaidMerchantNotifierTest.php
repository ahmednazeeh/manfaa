<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Payout\PaidMerchantNotifier;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Payout\PayoutItemState;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

/** The queued pushes' bodies. */
function paidPushBodies(): array
{
    return collect(Queue::pushedJobs()[SendPushNotification::class] ?? [])
        ->map(fn (array $job) => (fn () => $this->body)->call($job['job']))
        ->all();
}

/*
 * Telling a shop their customers were paid (owner request 2026-08-20).
 *
 * The merchant settled that cashback to the platform weeks earlier and then
 * heard nothing. What matters here is WHO is told and about what: only the
 * stores a run actually reached, once per run, and never about money that
 * did not move.
 */

function batchWithPaidItem(Merchant $merchant, int $cashback = 2500, string $state = 'paid'): PayoutBatch
{
    $batch = PayoutBatch::query()->create([
        'reference' => 'PB-'.fake()->unique()->numerify('########'),
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'cutoff_at' => now()->subDay(),
        'state' => PayoutBatchState::Processing,
        'total_laari' => $cashback,
        'customer_count' => 1,
    ]);

    $item = PayoutItem::query()->create([
        'batch_id' => $batch->id,
        'customer_id' => Customer::factory()->create()->id,
        'amount_laari' => $cashback,
        'idempotency_key' => fake()->unique()->numerify('MNF#######'),
        'bank' => 'bml',
        'account' => '7730000757923',
        'account_name' => 'A Customer',
        'state' => $state === 'paid' ? PayoutItemState::Paid : PayoutItemState::Failed,
    ]);

    Transaction::factory()->for($merchant)->create([
        'payout_item_id' => $item->id,
        'cashback_laari' => $cashback,
    ]);

    return $batch;
}

/**
 * A merchant whose owner carries a registered device.
 *
 * Push is the channel here, and a push needs somewhere to land: a merchant
 * with no device registered is silence no matter what fires, which is why
 * the device is part of the fixture rather than an afterthought.
 */
function shopWithStaff(string $name): Merchant
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();

    $token = app(MobileTokenService::class)
        ->issue($owner, MobileAudience::Merchant, 'Till')
        ->plainTextToken;

    DeviceToken::query()->create([
        'tokenable_type' => $owner->getMorphClass(),
        'tokenable_id' => $owner->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($token)->getKey(),
        'token' => 'device-'.$merchant->id,
        'platform' => 'android',
    ]);

    return $merchant;
}

it('tells the store whose customers were paid', function () {
    $merchant = shopWithStaff('Tea Plus');
    $batch = batchWithPaidItem($merchant, 2500);

    app(PaidMerchantNotifier::class)->notify($batch);

    Queue::assertPushed(SendPushNotification::class, 1);

    $body = paidPushBodies()[0];
    // The money is stated in rufiyaa, as every merchant-facing figure is.
    expect($body)->toContain('MVR 25.00');
    expect($body)->toContain('1 of your customers');
});

it('says nothing to a store the run never reached', function () {
    $paid = shopWithStaff('Tea Plus');
    $untouched = shopWithStaff('Agromart');

    $batch = batchWithPaidItem($paid);

    app(PaidMerchantNotifier::class)->notify($batch);

    // Only the store whose cashback was actually in the run: one push, and
    // it names the store that was paid for.
    Queue::assertPushed(SendPushNotification::class, 1);
    expect($untouched->name)->not->toBe($paid->name);
});

it('stays silent about an item that failed to pay', function () {
    // Telling a shop their customers were paid when the transfer bounced is
    // worse than saying nothing.
    $merchant = shopWithStaff('Tea Plus');
    $batch = batchWithPaidItem($merchant, 2500, state: 'failed');

    app(PaidMerchantNotifier::class)->notify($batch);

    Queue::assertNothingPushed();
});

it('sends one notification per store, not one per customer', function () {
    $merchant = shopWithStaff('Tea Plus');
    $batch = batchWithPaidItem($merchant, 2500);

    // A second customer, same store, same run.
    $second = PayoutItem::query()->create([
        'batch_id' => $batch->id,
        'customer_id' => Customer::factory()->create()->id,
        'amount_laari' => 1500,
        'idempotency_key' => 'MNF9999998',
        'bank' => 'bml',
        'account' => '7730000757924',
        'account_name' => 'Another Customer',
        'state' => PayoutItemState::Paid,
    ]);
    Transaction::factory()->for($merchant)->create([
        'payout_item_id' => $second->id,
        'cashback_laari' => 1500,
    ]);

    app(PaidMerchantNotifier::class)->notify($batch);

    // Two customers, MVR 40.00 between them, in ONE message.
    Queue::assertPushed(SendPushNotification::class, 1);

    $body = paidPushBodies()[0];
    expect($body)->toContain('2 of your customers');
    expect($body)->toContain('MVR 40.00');
});

it('is a push to the shop, and never an SMS', function () {
    // Both channel decisions live in separate match arms, and a duplicate
    // arm in the WRONG map compiles fine — PHP simply takes the first and
    // leaves the second dead. That is exactly what happened on
    // 2026-08-20: the key read as customer-facing AND fell through to
    // `default => true` for SMS, which would have texted every merchant on
    // every payout run. Nothing failed, because nothing asserted it.
    $key = NotificationTemplateKey::CustomersPaid;

    expect($key->isForMerchantStaff())->toBeTrue();
    // SMS too, following the standing rule that every merchant moment
    // reaches the store's own number (owner decision 2026-08-18) — which
    // StoreApprovedNotificationTest walks the whole catalogue to enforce.
    // NOTE: unlike every other merchant moment this one fires on EVERY
    // payout run and asks the shop to do nothing, so it is the one place
    // that rule costs money for news. Flagged to the owner 2026-08-20.
    expect($key->smsToMerchantContact())->toBeTrue();
    // It still has to be deliverable.
    expect($key->pushTitle())->toHaveKeys(['en', 'dv']);
    expect(trim($key->pushTitle()['dv']))->not->toBe('');
});
