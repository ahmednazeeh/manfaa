<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN §7 + decision 2026-08-15 (task #17b): suspension pauses cashback
 * CREATION, so a suspended store's COMMERCIAL OFFER is frozen with it — the
 * standing rate, promotions and the per-line category rate card all refuse
 * with 409 `store_not_trading`. Everything a suspended merchant needs in
 * order to STOP being suspended (settle, upload receipts, read every
 * screen, fix its profile, run branches and staff) stays open.
 *
 * Before this, EnsureMerchantApproved blocked only the three pre-approval
 * statuses, so a store suspended on day 16 for non-payment could still
 * publish a promotion — immutable once published, and therefore live the
 * instant an admin reinstated it.
 */
beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');

    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->actingAs($this->owner, 'merchant');

    $this->suspend = function (string $status = 'suspended'): void {
        $this->merchant->update(['status' => $status]);
        $this->owner->refresh()->load('merchant');
        $this->actingAs($this->owner, 'merchant');
    };
});

it('freezes the standing rate for a suspended store', function () {
    // Active: the change goes through.
    $this->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])->assertOk();

    ($this->suspend)();

    $response = $this->postJson('/api/merchant/rate', ['cashback_rate_percent' => '9.00'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_trading');

    // A shopkeeper suspended for non-payment must not be told to go and
    // finish a setup wizard they finished months ago.
    expect($response->json('message'))->not->toContain('setup wizard');
    expect($response->json('message'))->toContain('suspended');

    // Nothing was written: the rate is still the one set while trading.
    expect(MerchantRate::query()->where('merchant_id', $this->merchant->id)->max('rate_bp'))->toBe(300);
});

it('freezes promotion creation and publication for a suspended store', function () {
    $draft = $this->postJson('/api/merchant/promotions', [
        'cashback_rate_percent' => '5.00',
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDays(5)->toIso8601String(),
    ])->assertCreated()->json('data.id');

    ($this->suspend)();

    // The immutability rule (§7 — a published promotion cannot be ended
    // early) is exactly why publishing must be blocked here: the campaign
    // would go live the moment the store is reinstated.
    $this->postJson('/api/merchant/promotions/'.$draft.'/publish')
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_trading');

    $this->postJson('/api/merchant/promotions', [
        'cashback_rate_percent' => '8.00',
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDays(5)->toIso8601String(),
    ])->assertStatus(409)->assertJsonPath('code', 'store_not_trading');

    expect(Promotion::query()->where('merchant_id', $this->merchant->id)->where('status', 'published')->count())->toBe(0);

    // Cancelling a draft stays open — a suspended store must never be
    // stranded holding a draft it cannot withdraw.
    $this->postJson('/api/merchant/promotions/'.$draft.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('freezes the product-category rate card for a suspended store', function () {
    $id = $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Fruits', 'name_dv' => 'ދިވެހި',
        'mode' => 'excluded',
    ])->assertCreated()->json('data.id');

    ($this->suspend)();

    $this->postJson('/api/merchant/product-categories', ['name_en' => 'Veg', 'name_dv' => 'ދިވެހި', 'mode' => 'excluded'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_trading');

    $this->patchJson('/api/merchant/product-categories/'.$id, ['mode' => 'rate', 'cashback_rate_percent' => '5.00'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_trading');

    expect(MerchantProductCategory::query()->findOrFail($id)->mode)->toBe('excluded');

    // Reads stay open — the credit screen still has to price a line.
    $this->getJson('/api/merchant/product-categories')->assertOk();
});

it('freezes the offer for a CLOSED store too', function () {
    ($this->suspend)('closed');

    $this->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_trading');
});

it('still lets a suspended store fix its profile, branches and settlements — that is how suspension ends', function () {
    ($this->suspend)();

    $this->patchJson('/api/merchant/profile', ['contact_phone' => '+9607771234'])
        ->assertOk()
        ->assertJsonPath('data.contact_phone', '+9607771234');

    // A suspended store is LIVE, so its estate is reviewed like any other
    // (MR9) — the point here is that it is never REFUSED: 202, not 409.
    $this->postJson('/api/merchant/branches', ['name' => 'Hulhumale'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending_review');

    // The settlement path answers on its own terms (nothing to settle), but
    // never with the offer freeze.
    $response = $this->postJson('/api/merchant/settlements', []);
    expect($response->json('code'))->not->toBe('store_not_trading');
});

it('keeps the pre-approval refusal distinct from the suspension freeze', function () {
    $this->merchant->update(['status' => 'pending_review']);
    $this->owner->refresh()->load('merchant');
    $this->actingAs($this->owner, 'merchant');

    // A store awaiting review gets the wizard message and the wizard code —
    // the two conflicts are different conversations.
    $this->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_approved');
});
