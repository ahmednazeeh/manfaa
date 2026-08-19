<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Transfers\PaymentVerifier;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\MerchantUser;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\Suborder;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../Marketplace/fixtures.php';

/*
 * Auto-verifying a customer payment against the bank's own history.
 *
 * Two things must agree before money is accepted without a person looking:
 * the amount to the laari AND the payer's name. Most of this file is about
 * the cases where one of them does not.
 */

beforeEach(function (): void {
    config()->set('services.transfer.api_key', 'test-key');

    $this->profile = TransferProfile::create([
        'name' => 'MIB Faisanet',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    TransferSetting::current()->forceFill([
        'auto_verify_enabled' => true,
        'verify_window_minutes' => 15,
        'verify_min_score' => 60,
    ])->save();

    // The account the customer is TOLD to pay into, and how its history is
    // read. The mapping lives here rather than in one global setting
    // because customers choose their bank at checkout.
    $this->ourMib = PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
        'verify_profile_id' => $this->profile->id,
    ]);
});

/** An order sitting at proof_submitted, waiting to be matched. */
function awaitingOrder(string $name = 'Ahmed Nazeeh', int $laari = 12500): Order
{
    $customer = Customer::factory()->create(['name' => $name]);

    return Order::factory()->create([
        'customer_id' => $customer->id,
        // Paid into our MIB account, so MIB's history is what gets read.
        'payment_method' => 'mib',
        'total_payable_laari' => $laari,
        'payment_state' => 'proof_submitted',
        'state' => 'under_review',
        'proof_submitted_at' => now(),
        'poll_until' => now()->addMinutes(15),
    ]);
}

/** One row of MIB history, in the bank's own field names. */
function mibPayload(string $name, int $laari, string $reference = '804802801'): array
{
    return ['data' => [[
        'trxNumber2' => $reference,
        'baseAmount' => $laari / 100,
        'absAmount' => $laari / 100,
        'benefName' => $name,
        'trxDate' => '2026-08-19 10:00:00',
    ]]];
}

function mibRow(string $name, int $laari, string $reference = '804802801'): void
{
    Http::fake(['*/faisanet/history*' => Http::response(mibPayload($name, $laari, $reference))]);
}

it('verifies when the amount and the name both agree', function (): void {
    $order = awaitingOrder();
    mibRow('AHMD NAZEEH', 12500);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeTrue();

    $order->refresh();
    expect($order->payment_state)->toBe('verified');
    expect($order->auto_verified)->toBeTrue();
    expect($order->matched_trx_id)->toBe('804802801');
    expect($order->matched_payer_name)->toBe('AHMD NAZEEH');
    // No admin decided this. Filing one would put a person's name on a
    // machine's call.
    expect($order->verified_by)->toBeNull();
});

it('refuses when the amount is a single laari out', function (): void {
    $order = awaitingOrder(laari: 12500);
    mibRow('AHMD NAZEEH', 12499);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
    expect($order->refresh()->payment_state)->toBe('proof_submitted');
});

it('refuses when the name belongs to someone else', function (): void {
    $order = awaitingOrder('Ahmed Nazeeh');
    // Right amount, wrong person — the exact case a name check exists for.
    mibRow('MARIYAM SHIFA', 12500);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
    expect($order->refresh()->payment_state)->toBe('proof_submitted');
});

it('ignores an outgoing row of the same amount', function (): void {
    $order = awaitingOrder();
    Http::fake(['*/faisanet/history*' => Http::response(['data' => [[
        'trxNumber2' => '804802801',
        // Money we SENT. Never proof that money arrived.
        'baseAmount' => -125,
        'absAmount' => 125,
        'benefName' => 'AHMD NAZEEH',
    ]]])]);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
});

it('never spends one bank reference on two orders', function (): void {
    $first = awaitingOrder('Ahmed Nazeeh');
    // Same customer, same amount, same fifteen minutes — the case that
    // would otherwise verify both orders from one transfer.
    $second = awaitingOrder('Ahmed Nazeeh');

    mibRow('AHMD NAZEEH', 12500, '804802801');

    expect(app(PaymentVerifier::class)->attempt($first))->toBeTrue();
    expect(app(PaymentVerifier::class)->attempt($second))->toBeFalse();

    expect($first->refresh()->payment_state)->toBe('verified');
    expect($second->refresh()->payment_state)->toBe('proof_submitted');
});

it('cannot be forced to reuse a reference even past the pre-check', function (): void {
    $first = awaitingOrder('Ahmed Nazeeh');
    $second = awaitingOrder('Ahmed Nazeeh');

    mibRow('AHMD NAZEEH', 12500, '804802801');
    app(PaymentVerifier::class)->attempt($first);

    // The database itself refuses the second claim. This is what protects
    // us when two workers read the same history at the same instant and
    // both see the row unclaimed.
    expect(fn () => $second->forceFill(['matched_trx_id' => '804802801'])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('does nothing at all while the flag is off', function (): void {
    // The flag is the point: the bank tunnel is not live yet, so this whole
    // path ships dark until an operator turns it on.
    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    $order = awaitingOrder();
    mibRow('AHMD NAZEEH', 12500);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
    Http::assertNothingSent();
});

it('does nothing when the account has no profile to read it with', function (): void {
    $this->ourMib->forceFill(['verify_profile_id' => null])->save();

    $order = awaitingOrder();
    mibRow('AHMD NAZEEH', 12500);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
    Http::assertNothingSent();
});

it('reads the history of the bank the customer chose, not a fixed one', function (): void {
    // The bug this exists to stop: an order paid into BML must never be
    // matched against MIB's history. Before the routing fix, every BML
    // order would have sat unmatched while the screen said auto-verify was
    // on — or worse, matched a coincidence in the wrong bank's ledger.
    $order = awaitingOrder();
    $order->forceFill(['payment_method' => 'bml'])->save();

    mibRow('AHMD NAZEEH', 12500);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
    // Not merely unmatched — never asked. There is no BML account mapped,
    // so there is nothing to read.
    Http::assertNothingSent();
});

it('matches an order paid into a second bank once that bank is mapped', function (): void {
    $bml = TransferProfile::create([
        'name' => 'BML',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'bml',
        'bank' => 'bml',
        'active' => true,
    ]);

    PlatformBankAccount::query()->create([
        'bank_name' => 'bml',
        'account_no' => '7730000757923',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => false,
        'active' => true,
        'verify_profile_id' => $bml->id,
    ]);

    $order = awaitingOrder();
    $order->forceFill(['payment_method' => 'bml'])->save();

    Http::fake(['*/bml/history*' => Http::response([[
        'id' => 'row-1',
        'reference' => 'FT26082700001',
        'narrative3' => 'AHMD NAZEEH',
        'amount' => 125,
        'minus' => false,
    ]])]);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeTrue();
    expect($order->refresh()->matched_trx_id)->toBe('FT26082700001');
});

it('leaves an order a human already verified alone', function (): void {
    $order = awaitingOrder();
    $order->forceFill(['payment_state' => 'verified'])->save();
    mibRow('AHMD NAZEEH', 12500);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
});

it('respects a raised minimum score', function (): void {
    // 100 accepts nothing but an exact name.
    TransferSetting::current()->forceFill(['verify_min_score' => 100])->save();

    $order = awaitingOrder('Ahmed Nazeeh');
    mibRow('AHMD NAZEEH', 12500);

    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
});

it('tells the shops only when the payment actually matched', function (): void {
    // Told at VERIFICATION, not at placement: an unpaid order is not
    // something a shop should be interrupted for (owner requirement).
    // Asserted through the real push path rather than a mock, because what
    // matters is that a phone buzzes, not that a method was called.
    Queue::fake();

    DB::table('notification_templates')
        ->where('key', NotificationTemplateKey::OrderPlaced->value)
        ->update(['active' => true]);

    $order = awaitingOrder('Ahmed Nazeeh');
    $shop = vendor('Agromart');

    Suborder::factory()->create([
        'order_id' => $order->id,
        'merchant_id' => $shop['merchant']->id,
        'branch_id' => $shop['branch']->id,
        'reference' => 'MF-2026-2000-A',
        'subtotal_laari' => 12500,
    ]);

    $owner = MerchantUser::factory()->for($shop['merchant'])->owner()->create();

    // A push registration hangs off the auth token that made it, so the
    // device cannot outlive the sign-in.
    app(MobileTokenService::class)->issue($owner, MobileAudience::Merchant, 'Till');

    DeviceToken::query()->create([
        'tokenable_type' => $owner->getMorphClass(),
        'tokenable_id' => $owner->getKey(),
        'personal_access_token_id' => PersonalAccessToken::query()->latest('id')->value('id'),
        'token' => 'test-device-token',
        'platform' => 'android',
        'last_seen_at' => now(),
    ]);

    // A sequence, not two fakes: Http stubs are first-match-wins, so a
    // second fake for the same URL would never be reached.
    Http::fake(['*/faisanet/history*' => Http::sequence()
        ->push(mibPayload('MARIYAM SHIFA', 12500))
        ->push(mibPayload('AHMD NAZEEH', 12500))]);

    // Wrong payer: nothing may be sent.
    expect(app(PaymentVerifier::class)->attempt($order))->toBeFalse();
    Queue::assertNothingPushed();

    // Now the right one.
    expect(app(PaymentVerifier::class)->attempt($order))->toBeTrue();
    Queue::assertPushed(SendPushNotification::class, 1);
});
