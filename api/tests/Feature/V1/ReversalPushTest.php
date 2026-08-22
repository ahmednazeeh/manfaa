<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Jobs\SendCustomerSms;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * `cashback_reversed` (owner decision 2026-08-22): the other end of
 * `cashback_earned`. A customer told they earned cashback is told when it
 * is taken back, whichever way the reversal went — and by push only.
 */

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->fixture = SettlementFixture::payableBatch();
    $this->merchant = $this->fixture->merchant;
    $this->token = $this->merchant->createToken('till', ['transactions:reverse'])->plainTextToken;

    // The customer holds a phone with the app on it.
    $plain = app(MobileTokenService::class)->issue($this->fixture->customer, MobileAudience::Customer, 'Phone')->plainTextToken;
    DeviceToken::query()->create([
        'tokenable_type' => $this->fixture->customer->getMorphClass(),
        'tokenable_id' => $this->fixture->customer->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($plain)->getKey(),
        'token' => 'device-1',
        'platform' => 'android',
    ]);

    Queue::fake([SendPushNotification::class, SendCustomerSms::class]);
});

/** The job's fields are private; read them the way the payload will be built. */
function pushField(SendPushNotification $job, string $field): mixed
{
    $property = new ReflectionProperty($job, $field);

    return $property->getValue($job);
}

function reverseViaApi(int $id, string $reason = 'customer_refund')
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson("/api/v1/transactions/{$id}/reverse", ['reason' => $reason]);
}

it('tells the customer when their pending cashback is reversed in place', function () {
    $transaction = $this->fixture->transactions[0]; // cashback 2000, in no settlement

    reverseViaApi($transaction->id)->assertOk()->assertJsonPath('outcome', 'reversed');

    Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job): bool {
        return pushField($job, 'templateKey') === 'cashback_reversed'
            && pushField($job, 'title') === 'Cashback reversed'
            && pushField($job, 'body') === sprintf('Your MVR 20.00 cashback from %s was reversed after a refund. It no longer counts towards your next payout.', $this->merchant->name);
    });
    // Push only — no text, whatever the template's SMS setting.
    Queue::assertNotPushed(SendCustomerSms::class);
});

it('tells the customer when the reversal became a credit memo instead', function () {
    $transaction = $this->fixture->transactions[1];
    app(TransitionService::class)->confirm($transaction, Actor::system());

    reverseViaApi($transaction->id, 'till_void')->assertOk()->assertJsonPath('outcome', 'adjustment_created');

    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job): bool => pushField($job, 'templateKey') === 'cashback_reversed'
        && str_contains(pushField($job, 'body'), 'was reversed because the sale was voided.'));
});

it('says nothing about a sale that earned nothing', function () {
    $transaction = $this->fixture->transactions[0];
    $transaction->forceFill(['cashback_laari' => 0, 'fee_laari' => 0])->save();

    reverseViaApi($transaction->id)->assertOk();

    Queue::assertNotPushed(SendPushNotification::class);
});

it('sends once when the same reversal is replayed', function () {
    $transaction = $this->fixture->transactions[0];
    $key = (string) Str::uuid();

    $send = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Idempotency-Key' => $key])
        ->postJson("/api/v1/transactions/{$transaction->id}/reverse", ['reason' => 'other']);

    $send()->assertOk();
    $send()->assertOk()->assertHeader('Idempotency-Replay', 'true');

    Queue::assertPushed(SendPushNotification::class, 1);
});
