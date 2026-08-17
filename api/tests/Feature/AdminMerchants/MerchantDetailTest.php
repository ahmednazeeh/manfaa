<?php

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('rejects unauthenticated access to the merchant detail', function () {
    $merchant = Merchant::factory()->create();

    $this->getJson("/api/admin/merchants/{$merchant->id}")->assertUnauthorized();
});

it('serves the full merchant record to any admin', function () {
    $now = CarbonImmutable::parse('2026-08-20T12:00:00+00:00');
    Carbon::setTestNow($now);

    $zone = Zone::create(['name' => 'Malé', 'polygon' => [
        ['lat' => 4.16, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.49],
        ['lat' => 4.18, 'lng' => 73.52],
        ['lat' => 4.16, 'lng' => 73.52],
    ]]);

    $merchant = Merchant::factory()->create([
        'name' => 'Alpha Mart',
        'name_dv' => 'އަލްފާ މާޓް',
        'category' => 'grocery',
        'channel' => 'both',
        'contact_email' => 'owner@alphamart.mv',
        'contact_phone' => '+9607771234',
        'support_phone' => '+9603331234',
        'website_url' => 'https://alphamart.mv',
    ]);

    // A pinned branch inside the zone and an unpinned one outside any.
    MerchantBranch::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Main',
        'address' => 'Majeedhee Magu',
        'lat' => 4.17,
        'lng' => 73.50,
    ]);
    MerchantBranch::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Warehouse',
    ]);

    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 250,
        'effective_from' => $now->subMonth(),
        'effective_to' => null,
    ]);

    $owner = MerchantUser::factory()->owner()->for($merchant)->create(['name' => 'Aishath Owner']);
    MerchantUser::factory()->staff()->for($merchant)->create(['is_active' => false]);

    // One overdue payable, one current, one already confirmed (excluded).
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $now->subDays(20),
        'due_at' => $now->subDays(5),
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);
    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $now->subDays(2),
        'due_at' => $now->addDays(13),
        'cashback_laari' => 1_000,
        'fee_laari' => 375,
        'fee_gst_laari' => 0,
    ]);
    Transaction::factory()->for($merchant)->create([
        'state' => 'confirmed',
        'cashback_laari' => 9_999,
        'fee_laari' => 9_999,
        'fee_gst_laari' => 0,
    ]);

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson("/api/admin/merchants/{$merchant->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $merchant->id)
        ->assertJsonPath('data.name', 'Alpha Mart')
        ->assertJsonPath('data.name_dv', 'އަލްފާ މާޓް')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.category', 'grocery')
        ->assertJsonPath('data.channel', 'both')
        ->assertJsonPath('data.contact_email', 'owner@alphamart.mv')
        ->assertJsonPath('data.support_phone', '+9603331234')
        ->assertJsonPath('data.website_url', 'https://alphamart.mv')
        // PLAN §1 wire format: percent string, never bp.
        ->assertJsonPath('data.cashback_rate_percent', '2.50')
        ->assertJsonPath('data.standing.open_payable_count', 2)
        ->assertJsonPath('data.standing.outstanding_laari', 2_750 + 1_375)
        ->assertJsonPath('data.standing.overdue_laari', 2_750)
        ->assertJsonCount(2, 'data.branches')
        ->assertJsonPath('data.branches.0.name', 'Main')
        ->assertJsonPath('data.branches.0.zone.id', $zone->id)
        ->assertJsonPath('data.branches.0.zone.name', 'Malé')
        ->assertJsonPath('data.branches.1.zone', null)
        ->assertJsonCount(2, 'data.staff')
        ->assertJsonPath('data.staff.0.id', $owner->id)
        ->assertJsonPath('data.staff.0.name', 'Aishath Owner')
        ->assertJsonPath('data.staff.0.role.is_owner', true)
        ->assertJsonPath('data.staff.0.is_active', true)
        ->assertJsonPath('data.staff.1.is_active', false);
});

it('answers 404 for an unknown merchant id', function () {
    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson('/api/admin/merchants/999999')
        ->assertNotFound();
});
