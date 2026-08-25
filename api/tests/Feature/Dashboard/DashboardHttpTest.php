<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * GET /api/admin/dashboard — the console landing, in one call.
 *
 * This file holds the SHAPE and the GATE: who may ask, what comes back, what
 * the window defaults to. The numbers themselves are pinned in the sibling
 * files (attention, auto-match, series) and tied to the Reports page in
 * DashboardReportsAgreementTest.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->admin = AdminUser::factory()->create(['role' => 'admin']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

const DASHBOARD_AUGUST = '/api/admin/dashboard?from=2026-08-01&to=2026-08-31';

it('serves every panel to a superadmin, money and chart included', function (): void {
    $fixture = ReportFixture::payable([100_000, 50_000]);
    $fixture->payAndMatch($fixture->submit(), 4_125);

    $response = $this->actingAs($this->superadmin, 'admin')
        ->getJson(DASHBOARD_AUGUST)
        ->assertOk();

    $response
        ->assertJsonPath('period.from', '2026-08-01')
        ->assertJsonPath('period.to', '2026-08-31')
        ->assertJsonPath('period.timezone', 'Indian/Maldives')
        ->assertJsonPath('period.days', 31)
        ->assertJsonPath('can_view_money', true)
        // The preceding window of equal length, adjacent to this one.
        ->assertJsonPath('money.previous.period.from', '2026-07-01')
        ->assertJsonPath('money.previous.period.to', '2026-07-31')
        ->assertJsonStructure([
            'period' => ['from', 'to', 'timezone', 'days'],
            'generated_at',
            'can_view_money',
            'attention' => [
                'settlements_payment_review',
                'wallet_top_ups_pending',
                'store_reviews_pending',
                'change_requests_pending',
                'holds_open',
                'total',
            ],
            'auto_match' => [
                'settlement_payments' => [
                    'pending_total',
                    'watching_now',
                    'waiting_on_human' => ['total', 'window_expired', 'never_watched', 'no_verify_profile', 'auto_verify_off'],
                    'expired_unmatched_24h',
                    'matched_in_period' => ['total', 'auto', 'manual', 'auto_rate_percent'],
                ],
                'wallet_top_ups' => [
                    'pending_total',
                    'watching_now',
                    'waiting_on_human' => ['total', 'window_expired', 'never_watched', 'no_verify_profile', 'auto_verify_off'],
                    'expired_unmatched_24h',
                    'matched_in_period' => ['total', 'auto', 'manual', 'auto_rate_percent'],
                ],
            ],
            'growth' => [
                'customers' => ['total', 'new_in_period'],
                'merchants' => ['active_total', 'new_active_in_period', 'registered_in_period'],
            ],
            'money' => [
                'cashback_generated_laari',
                'platform_fees_net_laari',
                'gst_collected_laari',
                'collected_from_merchants_laari',
                'paid_out_to_customers_laari',
                'previous' => [
                    'period',
                    'cashback_generated_laari',
                    'platform_fees_net_laari',
                    'gst_collected_laari',
                    'collected_from_merchants_laari',
                    'paid_out_to_customers_laari',
                ],
            ],
            'series' => [['date', 'cashback_laari', 'fee_accrued_laari', 'collected_laari', 'paid_out_laari']],
        ]);

    // GST is a liability, never income, and it is zero everywhere today.
    expect($response->json('money.gst_collected_laari'))->toBe(0);
});

it('omits money and the chart for a plain admin, and stays well-formed', function (): void {
    $fixture = ReportFixture::payable([100_000]);
    $fixture->payAndMatch($fixture->submit(), 2_750);

    $response = $this->actingAs($this->admin, 'admin')
        ->getJson(DASHBOARD_AUGUST)
        ->assertOk();

    $response
        ->assertJsonPath('can_view_money', false)
        ->assertJsonMissingPath('money')
        ->assertJsonMissingPath('series')
        // Everything a plain admin IS entitled to is still here, and still
        // populated — the gate removes a panel, it does not blank the page.
        ->assertJsonStructure([
            'period',
            'attention' => ['total'],
            'auto_match' => ['settlement_payments', 'wallet_top_ups'],
            'growth' => ['customers', 'merchants'],
        ]);

    expect($response->json('growth.merchants.active_total'))->toBe(1);
});

it('defaults to the business month in progress', function (): void {
    // 09:00 UTC on 20 August is 14:00 in Malé, the same day. The default
    // window is the 1st to today, in BUSINESS time.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T09:00:00+00:00'));

    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('period.from', '2026-08-01')
        ->assertJsonPath('period.to', '2026-08-20')
        ->assertJsonPath('period.days', 20)
        // 20 days of August are answered by the 20 days before them.
        ->assertJsonPath('money.previous.period.from', '2026-07-12')
        ->assertJsonPath('money.previous.period.to', '2026-07-31');
});

it('reads the default window in MALDIVES time, not UTC', function (): void {
    // 20:00 UTC on 31 July is 01:00 on 1 August in Malé: the business month
    // has already turned, and the dashboard must say August.
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-31T20:00:00+00:00'));

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('period.from', '2026-08-01')
        ->assertJsonPath('period.to', '2026-08-01')
        ->assertJsonPath('period.days', 1);
});

it('refuses half a window, a backwards one, and one too long', function (): void {
    $this->actingAs($this->superadmin, 'admin');

    $this->getJson('/api/admin/dashboard?from=2026-08-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson('/api/admin/dashboard?to=2026-08-31')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    $this->getJson('/api/admin/dashboard?from=2026-08-31&to=2026-08-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson('/api/admin/dashboard?from=01-08-2026&to=31-08-2026')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    // A year and a day, the same ceiling the reports carry.
    $this->getJson('/api/admin/dashboard?from=2025-08-01&to=2026-08-31')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

it('is closed to everyone but an admin', function (): void {
    $this->getJson(DASHBOARD_AUGUST)->assertUnauthorized();

    $fixture = ReportFixture::payable([100_000]);

    $this->actingAs($fixture->user, 'merchant')
        ->getJson(DASHBOARD_AUGUST)
        ->assertUnauthorized();

    app('auth')->forgetGuards();

    $this->actingAs(Customer::factory()->create(), 'customer')
        ->getJson(DASHBOARD_AUGUST)
        ->assertUnauthorized();

    expect($fixture->user)->toBeInstanceOf(MerchantUser::class);
});
