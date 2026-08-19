<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Domain\Transfers\PaymentVerifier;
use App\Jobs\PollOrderPayment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Watching the bank for one order's money, for fifteen minutes and no longer.
 *
 * The window is re-read from the row rather than counted in the job, so a
 * worker restarted mid-window resumes where the clock actually is instead of
 * starting the fifteen minutes again.
 */

beforeEach(function (): void {
    config()->set('services.transfer.api_key', 'test-key');

    $profile = TransferProfile::create([
        'name' => 'MIB Faisanet',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
        'verify_profile_id' => $profile->id,
    ]);

    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    TransferSetting::current()->forceFill([
        'auto_verify_enabled' => true,
        'verify_window_minutes' => 15,
        'verify_min_score' => 60,
    ])->save();
});

function pollableOrder(): Order
{
    return Order::factory()->create([
        'customer_id' => Customer::factory()->create(['name' => 'Ahmed Nazeeh'])->id,
        'total_payable_laari' => 12500,
        'payment_method' => 'mib',
        'payment_state' => 'proof_submitted',
        'state' => 'under_review',
        'proof_submitted_at' => now(),
        'poll_started_at' => now(),
        'poll_until' => now()->addMinutes(15),
    ]);
}

it('verifies the order and stops looking', function (): void {
    Queue::fake();
    Http::fake(['*/faisanet/history*' => Http::response(['data' => [[
        'trxNumber2' => '804802801',
        'baseAmount' => 125,
        'benefName' => 'AHMD NAZEEH',
    ]]])]);

    $order = pollableOrder();
    (new PollOrderPayment($order->id))->handle(app(PaymentVerifier::class));

    expect($order->refresh()->payment_state)->toBe('verified');
    // Matched, so nothing is queued to look again.
    Queue::assertNotPushed(PollOrderPayment::class);
});

it('looks again in a minute when nothing matched yet', function (): void {
    Queue::fake();
    // The customer's transfer has not landed in the bank's history yet,
    // which is the ordinary case in the first minute.
    Http::fake(['*/faisanet/history*' => Http::response(['data' => []])]);

    $order = pollableOrder();
    (new PollOrderPayment($order->id))->handle(app(PaymentVerifier::class));

    expect($order->refresh()->poll_attempts)->toBe(1);
    Queue::assertPushed(PollOrderPayment::class, 1);
});

it('gives up when the window has closed', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => []])]);

    $order = pollableOrder();
    // Fifteen minutes gone. The order stays in the admin queue, which is
    // where an unmatched payment belongs.
    $order->forceFill(['poll_until' => now()->subMinute()])->save();

    (new PollOrderPayment($order->id))->handle(app(PaymentVerifier::class));

    Queue::assertNotPushed(PollOrderPayment::class);
    Http::assertNothingSent();
});

it('stops when the flag was switched off while it was queued', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => []])]);

    $order = pollableOrder();
    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    (new PollOrderPayment($order->id))->handle(app(PaymentVerifier::class));

    Queue::assertNotPushed(PollOrderPayment::class);
    Http::assertNothingSent();
});

it('stops once an admin has verified the order by hand', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => []])]);

    $order = pollableOrder();
    $order->forceFill(['payment_state' => 'verified'])->save();

    (new PollOrderPayment($order->id))->handle(app(PaymentVerifier::class));

    Queue::assertNotPushed(PollOrderPayment::class);
});

it('opens the window on receipt upload only while the flag is on', function (): void {
    Queue::fake();

    $customer = Customer::factory()->create(['name' => 'Ahmed Nazeeh']);
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'payment_method' => 'mib',
        'payment_state' => 'awaiting_proof',
        'total_payable_laari' => 12500,
    ]);

    $upload = fn () => $this->actingAs($customer, 'customer')->post(
        "/api/customer/orders/{$order->id}/receipt",
        ['receipt' => UploadedFile::fake()->image('slip.jpg')],
    );

    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();
    $upload()->assertOk();
    Queue::assertNotPushed(PollOrderPayment::class);

    TransferSetting::current()->forceFill(['auto_verify_enabled' => true])->save();
    $upload()->assertOk();
    Queue::assertPushed(PollOrderPayment::class, 1);

    $order->refresh();
    // Fifteen minutes from the upload, on the clock.
    expect($order->poll_until->diffInMinutes($order->poll_started_at, true))->toBe(15.0);
});
