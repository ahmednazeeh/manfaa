<?php

declare(strict_types=1);

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Platform\PlatformConfig;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create([
        'name' => 'Original Name',
        'settlement_method' => 'bank',
        'min_eligible_laari' => 5000,
        'validation_window_days' => 3,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');
});

it('shows and updates the profile', function () {
    $this->getJson('/api/merchant/profile')
        ->assertOk()
        ->assertJsonPath('data.name', 'Original Name')
        ->assertJsonPath('data.contact_email', null);

    $this->patchJson('/api/merchant/profile', [
        'category' => 'restaurant',
        'channel' => 'both',
        'eligibility_basis' => 'Food and drink, excluding service charge.',
        'contact_email' => 'owner@store.mv',
        'contact_phone' => '+9607771234',
    ])->assertOk()
        ->assertJsonPath('data.category', 'restaurant')
        ->assertJsonPath('data.channel', 'both')
        ->assertJsonPath('data.contact_email', 'owner@store.mv');

    $merchant = $this->merchant->refresh();

    expect($merchant->category)->toBe('restaurant')
        ->and($merchant->channel)->toBe('both')
        ->and($merchant->eligibility_basis)->toBe('Food and drink, excluding service charge.')
        ->and($merchant->contact_phone)->toBe('+9607771234');
});

it('rejects a category outside the curated list and an unknown channel', function () {
    $this->patchJson('/api/merchant/profile', ['category' => 'F&B'])->assertUnprocessable();
    $this->patchJson('/api/merchant/profile', ['channel' => 'metaverse'])->assertUnprocessable();

    // Inactive curated categories are not pickable either.
    StoreCategory::query()->where('slug', 'beauty')->update(['active' => false]);
    $this->patchJson('/api/merchant/profile', ['category' => 'beauty'])->assertUnprocessable();
});

it('clears nullable profile fields explicitly', function () {
    $this->merchant->update(['contact_email' => 'old@store.mv', 'category' => 'grocery']);

    $this->patchJson('/api/merchant/profile', ['contact_email' => null, 'category' => null])
        ->assertOk()
        ->assertJsonPath('data.contact_email', null)
        ->assertJsonPath('data.category', null);
});

it('renames the business without moving its slug', function () {
    $slugBefore = $this->merchant->slug;

    $this->patchJson('/api/merchant/profile', ['name' => 'Sneaky Rebrand', 'category' => 'cafe'])
        ->assertOk();

    // The name is the store's to change; the slug is not, because it is the
    // address already printed on QR codes and shared in messages.
    expect($this->merchant->refresh()->name)->toBe('Sneaky Rebrand')
        ->and($this->merchant->slug)->toBe($slugBefore);
});

it('drops the discovery read model so the storefront never serves a stale card', function () {
    // Both keys warm, as a live storefront read would leave them.
    Cache::put(DiscoveryService::CACHE_KEY, [['slug' => $this->merchant->slug]], 60);
    Cache::put(DiscoveryService::STORE_CACHE_PREFIX.$this->merchant->slug, ['name' => 'stale'], 60);

    $this->patchJson('/api/merchant/profile', [
        'category' => 'cafe',
        'channel' => 'online',
        'eligibility_basis' => 'New terms.',
    ])->assertOk();

    // The card (category, channel) and the store page (terms) both render
    // these — a 60s stale window would show the previous values. The logo
    // path has always dropped them; the profile save now does too.
    expect(Cache::has(DiscoveryService::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(DiscoveryService::STORE_CACHE_PREFIX.$this->merchant->slug))->toBeFalse();
});

it('keeps saving when the store holds a category the superadmin has since retired', function () {
    $this->merchant->update(['category' => 'beauty']);
    StoreCategory::query()->where('slug', 'beauty')->update(['active' => false]);

    // The panel PATCHes the whole form, so before this fix editing a phone
    // number 422'd on `category` until the owner re-picked one — a save trap
    // triggered by an admin action the merchant never saw.
    $this->patchJson('/api/merchant/profile', [
        'category' => 'beauty',
        'contact_phone' => '+9607779999',
    ])->assertOk()
        ->assertJsonPath('data.category', 'beauty')
        ->assertJsonPath('data.category_retired', true)
        ->assertJsonPath('data.contact_phone', '+9607779999');

    // Omitting it entirely — what the panel now sends — works too.
    $this->patchJson('/api/merchant/profile', ['contact_phone' => '+9607778888'])
        ->assertOk()
        ->assertJsonPath('data.category', 'beauty')
        ->assertJsonPath('data.category_retired', true);

    // But it stays a one-way door: moving to a DIFFERENT retired category is
    // still refused, and the flag clears once an active one is picked.
    StoreCategory::query()->where('slug', 'grocery')->update(['active' => false]);
    $this->patchJson('/api/merchant/profile', ['category' => 'grocery'])->assertUnprocessable();

    $this->patchJson('/api/merchant/profile', ['category' => 'cafe'])
        ->assertOk()
        ->assertJsonPath('data.category_retired', false);
});

it('reports category_retired on the profile read so the panel can prompt a re-pick', function () {
    $this->merchant->update(['category' => 'cafe']);

    $this->getJson('/api/merchant/profile')->assertOk()->assertJsonPath('data.category_retired', false);

    StoreCategory::query()->where('slug', 'cafe')->update(['active' => false]);

    $this->getJson('/api/merchant/profile')->assertOk()->assertJsonPath('data.category_retired', true);

    // A store with no category set is not "retired", it is simply unset.
    // (actingAs holds one user instance for the whole test, so its merchant
    // relation must be re-resolved after a direct DB write.)
    $this->merchant->update(['category' => null]);
    $this->actingAs($this->owner->fresh(), 'merchant');
    $this->getJson('/api/merchant/profile')->assertOk()->assertJsonPath('data.category_retired', false);
});

it('rejects an invalid contact email', function () {
    $this->patchJson('/api/merchant/profile', ['contact_email' => 'not-an-email'])
        ->assertUnprocessable();
});

it('updates the bank identity as one atomic triple', function () {
    $this->patchJson('/api/merchant/bank-account', [
        'bank_name' => 'bml',
        'bank_account' => '7730000123456',
        'bank_account_name' => 'Original Name Pvt Ltd',
    ])->assertOk()
        ->assertJsonPath('data.bank_name', 'bml')
        ->assertJsonPath('data.bank_account', '7730000123456')
        ->assertJsonPath('data.bank_account_name', 'Original Name Pvt Ltd');

    // A partial identity mismatches every payment: all three are required.
    $this->patchJson('/api/merchant/bank-account', [
        'bank_name' => 'mib',
        'bank_account' => '9990000',
    ])->assertUnprocessable();

    expect($this->merchant->refresh()->bank_name)->toBe('bml');
});

it('updates preferences within platform bounds', function () {
    $this->patchJson('/api/merchant/preferences', [
        'settlement_method' => 'wallet',
        'min_eligible_laari' => 100000,
        'validation_window_days' => 0,
    ])->assertOk()
        ->assertJsonPath('data.settlement_method', 'wallet')
        ->assertJsonPath('data.min_eligible_laari', 100000)
        ->assertJsonPath('data.validation_window_days', 0)
        ->assertJsonPath('data.validation_window_max_days', 3);

    // Back up to the platform ceiling (default window, 3 days) is fine.
    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 3])
        ->assertOk()
        ->assertJsonPath('data.validation_window_days', 3);
});

it('rejects out-of-bounds preferences', function () {
    $this->patchJson('/api/merchant/preferences', ['settlement_method' => 'cash'])->assertUnprocessable();
    $this->patchJson('/api/merchant/preferences', ['min_eligible_laari' => -1])->assertUnprocessable();
    $this->patchJson('/api/merchant/preferences', ['min_eligible_laari' => 100001])->assertUnprocessable();
    $this->patchJson('/api/merchant/preferences', ['min_eligible_laari' => 50.5])->assertUnprocessable();
    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => -1])->assertUnprocessable();
    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 4])->assertUnprocessable();
    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 31])->assertUnprocessable();

    $merchant = $this->merchant->refresh();

    expect($merchant->settlement_method)->toBe('bank')
        ->and($merchant->min_eligible_laari)->toBe(5000)
        ->and($merchant->validation_window_days)->toBe(3);
});

it('caps the validation window at the platform-governed bound — the §11 stale review is not merchant-tunable', function () {
    // CreditRecorder only holds a backdated manual credit for admin review
    // past validation_window_days + 3, and the merchant-mediated claims
    // decision leans on that hold. Self-raising the window to 30 would let
    // fabricated "missed sale" credits up to 33 days old skip the on_hold
    // review entirely (and defer the merchant's own settlement clock) — so
    // the ceiling belongs to the ADMIN's platform settings, not the party
    // the control polices.
    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 30])->assertUnprocessable();

    expect($this->merchant->refresh()->validation_window_days)->toBe(3);

    // The admin raises the platform window; the merchant ceiling follows.
    app(PlatformConfig::class)->set('default_validation_window_days', 10);

    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 10])
        ->assertOk()
        ->assertJsonPath('data.validation_window_days', 10)
        ->assertJsonPath('data.validation_window_max_days', 10);

    $this->patchJson('/api/merchant/preferences', ['validation_window_days' => 11])->assertUnprocessable();
});

it('renames the store without moving its link', function () {
    // The slug is the address on every QR code and shared link already in
    // circulation. A rebrand changes the words on the page; moving the door
    // would strand everyone holding the old one.
    $slugBefore = $this->merchant->slug;

    $this->patchJson('/api/merchant/profile', ['name' => 'Chai House'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Chai House');

    $merchant = $this->merchant->refresh();

    expect($merchant->name)->toBe('Chai House')
        ->and($merchant->slug)->toBe($slugBefore);
});

it('materialises the support phone: blank copies contact, a matching copy follows a contact change', function () {
    // "Same as the contact number" saves the contact number itself — the
    // old NULL-means-same convention reached the customer app as a store
    // with no phone at all (Merchant::booted()).
    $this->patchJson('/api/merchant/profile', [
        'contact_phone' => '+9607771234',
        'support_phone' => null,
    ])->assertOk();

    expect($this->merchant->refresh()->support_phone)->toBe('+9607771234');

    // The stored copy follows the contact number, so it cannot go stale…
    $this->patchJson('/api/merchant/profile', ['contact_phone' => '+9609998877'])->assertOk();
    expect($this->merchant->refresh()->support_phone)->toBe('+9609998877');

    // …but an explicitly distinct support line is respected and stays put.
    $this->patchJson('/api/merchant/profile', ['support_phone' => '+9603334455'])->assertOk();
    $this->patchJson('/api/merchant/profile', ['contact_phone' => '+9601112233'])->assertOk();

    $merchant = $this->merchant->refresh();
    expect($merchant->contact_phone)->toBe('+9601112233')
        ->and($merchant->support_phone)->toBe('+9603334455');
});
