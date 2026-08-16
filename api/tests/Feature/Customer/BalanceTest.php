<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    $this->customer = Customer::factory()->create();
    $this->merchant = Merchant::factory()->create();
});

function balanceTx(Customer $customer, Merchant $merchant, string $state, int $cashbackLaari): Transaction
{
    return Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'state' => $state,
        'cashback_laari' => $cashbackLaari,
    ]);
}

it('reports confirmed as the headline, never summed with pending', function () {
    balanceTx($this->customer, $this->merchant, 'confirmed', 3000);
    balanceTx($this->customer, $this->merchant, 'confirmed', 2000);

    balanceTx($this->customer, $this->merchant, 'tracked', 100);
    balanceTx($this->customer, $this->merchant, 'awaiting_validation', 1500);
    balanceTx($this->customer, $this->merchant, 'payable_unfunded', 4000);
    balanceTx($this->customer, $this->merchant, 'on_hold', 400);

    // Terminal states counted in NEITHER figure.
    balanceTx($this->customer, $this->merchant, 'reversed', 700);
    balanceTx($this->customer, $this->merchant, 'written_off', 900);

    // Another customer's confirmed money never bleeds in.
    balanceTx(Customer::factory()->create(), $this->merchant, 'confirmed', 99999);

    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.confirmed_laari', 5000)
        ->assertJsonPath('data.pending_laari', 6000)
        ->assertJsonPath('data.minimum_payout_laari', 10000)
        ->assertJsonPath('data.currency', 'MVR');
});

it('counts paid cashback this month from the event log', function () {
    Carbon::setTestNow('2026-08-14T12:00:00+05:00');

    $paidNow = balanceTx($this->customer, $this->merchant, 'paid', 2500);
    $paidNow->events()->create([
        'from_state' => 'confirmed',
        'to_state' => 'paid',
        'actor_type' => 'system',
        'created_at' => CarbonImmutable::now('UTC'),
    ]);

    // Paid LAST month (business time) — excluded.
    $paidBefore = balanceTx($this->customer, $this->merchant, 'paid', 8000);
    $paidBefore->events()->create([
        'from_state' => 'confirmed',
        'to_state' => 'paid',
        'actor_type' => 'system',
        'created_at' => CarbonImmutable::parse('2026-07-30T12:00:00+05:00')->utc(),
    ]);

    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.paid_this_month_laari', 2500);
});

it('reports this month’s payout window before the 24th cutoff', function () {
    Carbon::setTestNow('2026-08-10T12:00:00+05:00');

    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.next_payout_window.starts_at', '2026-08-25')
        ->assertJsonPath('data.next_payout_window.ends_at', '2026-08-31');
});

it('still reports this month right up to the cutoff instant', function () {
    Carbon::setTestNow('2026-08-24T23:30:00+05:00');

    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.next_payout_window.starts_at', '2026-08-25');
});

it('rolls the payout window to next month after the 24th 23:59 cutoff', function () {
    Carbon::setTestNow('2026-08-25T00:30:00+05:00');

    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.next_payout_window.starts_at', '2026-09-25')
        ->assertJsonPath('data.next_payout_window.ends_at', '2026-09-30');
});

it('reports whether a payout account exists', function () {
    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', false);

    $this->customer->forceFill([
        'payout_bank' => 'bml',
        'payout_account' => '7701234567890',
        'payout_account_name' => 'AISHATH MANIKE',
    ])->save();

    $this->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', true);
});

it('requires customer auth', function () {
    $this->getJson('/api/customer/balance')->assertUnauthorized();
});
