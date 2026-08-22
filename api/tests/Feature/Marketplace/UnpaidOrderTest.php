<?php

declare(strict_types=1);

use App\Domain\Marketplace\FulfilmentException;
use App\Domain\Marketplace\FulfilmentService;
use App\Domain\Marketplace\MarketplaceCashbackService;
use App\Domain\Marketplace\MerchantPayoutBuilder;
use App\Models\CustomerRefund;
use App\Models\CustomerWallet;
use App\Models\Order;
use App\Models\Suborder;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/fixtures.php';

/*
 * NOTHING happens to an order until the money has arrived.
 *
 * The security audit of 2026-08-19 found this gate missing everywhere, and
 * it was the platform's worst hole: rejecting an unpaid order — the ordinary
 * thing for a shop to do with one — credited its full value to the
 * customer's wallet as withdrawable money. The platform paid out money it
 * had never received, triggered by the shop behaving correctly.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    $this->vendor = vendor('Island Mart');
});

/** An order sitting unpaid, exactly as checkout creates it. */
function unpaidSuborder(array $vendorFixture, string $paymentState = 'awaiting_proof'): Suborder
{
    $order = Order::factory()->create([
        'payment_state' => $paymentState,
        'state' => 'placed',
        'total_payable_laari' => 1000000,
    ]);

    return Suborder::factory()->create([
        'order_id' => $order->id,
        'merchant_id' => $vendorFixture['merchant']->id,
        'branch_id' => $vendorFixture['branch']->id,
        'reference' => 'MF-2026-9001-A',
        'state' => 'new',
        'items_laari' => 1000000,
        'subtotal_laari' => 1000000,
        'cashback_laari' => 20000,
        'payable_to_merchant_laari' => 960000,
    ]);
}

it('refuses to reject an unpaid order, and mints no refund', function (): void {
    $suborder = unpaidSuborder($this->vendor);

    // The attack: reject is the NORMAL action on an order nobody paid for.
    expect(fn () => app(FulfilmentService::class)->reject($suborder, 'no payment'))
        ->toThrow(FulfilmentException::class);

    // Not a laari of it reached anybody's wallet.
    expect(CustomerRefund::query()->count())->toBe(0);
    expect(CustomerWallet::query()->sum('balance_laari'))->toBe(0);
});

it('refuses to accept or advance an unpaid order', function (): void {
    $suborder = unpaidSuborder($this->vendor);

    expect(fn () => app(FulfilmentService::class)->accept($suborder))
        ->toThrow(FulfilmentException::class);

    expect(fn () => app(FulfilmentService::class)->advance($suborder, 'accepted'))
        ->toThrow(FulfilmentException::class);
});

it('refuses to amend an unpaid order', function (): void {
    $suborder = unpaidSuborder($this->vendor);
    $suborder->forceFill(['state' => 'accepted'])->save();

    expect(fn () => app(FulfilmentService::class)->amend($suborder, null, [], 'out of stock'))
        ->toThrow(FulfilmentException::class);

    expect(CustomerRefund::query()->count())->toBe(0);
});

it('lets a VERIFIED order be rejected and refunded, as it always should', function (): void {
    $suborder = unpaidSuborder($this->vendor, 'verified');

    app(FulfilmentService::class)->reject($suborder, 'out of stock');

    // The honest path still works — the gate is about payment, not about
    // making refunds harder.
    expect(CustomerRefund::query()->count())->toBe(1);
    expect((int) CustomerRefund::query()->sole()->amount_laari)->toBe(1000000);
});

it('never pays a shop for an order the customer did not pay for', function (): void {
    $suborder = unpaidSuborder($this->vendor);
    // Forced past the state machine, as a direct database write or an older
    // row could be: the payout query must refuse on its own account.
    $suborder->forceFill([
        'state' => 'delivered',
        'delivered_at' => CarbonImmutable::now()->subDays(30),
    ])->save();

    $payable = app(MerchantPayoutBuilder::class)
        ->payableSuborders(CarbonImmutable::now())
        ->get();

    expect($payable)->toHaveCount(0);
});

it('pays a shop for an order that WAS paid for', function (): void {
    $suborder = unpaidSuborder($this->vendor, 'verified');
    $suborder->forceFill([
        'state' => 'delivered',
        'delivered_at' => CarbonImmutable::now()->subDays(30),
    ])->save();

    expect(app(MerchantPayoutBuilder::class)
        ->payableSuborders(CarbonImmutable::now())
        ->get())->toHaveCount(1);
});

it('credits no cashback for an unpaid order', function (): void {
    $suborder = unpaidSuborder($this->vendor);
    $suborder->forceFill(['state' => 'delivered'])->save();

    expect(app(MarketplaceCashbackService::class)->credit($suborder->refresh()))->toBeNull();
    expect(Transaction::query()->where('origin', 'marketplace')->count())->toBe(0);
});
