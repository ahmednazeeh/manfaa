<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('never lets the profile rename the business — identity is admin-only', function () {
    $this->patchJson('/api/merchant/profile', ['name' => 'Sneaky Rebrand', 'category' => 'cafe'])
        ->assertOk();

    expect($this->merchant->refresh()->name)->toBe('Original Name');
});

it('rejects an invalid contact email', function () {
    $this->patchJson('/api/merchant/profile', ['contact_email' => 'not-an-email'])
        ->assertUnprocessable();
});

it('updates the bank identity as one atomic triple', function () {
    $this->patchJson('/api/merchant/bank-account', [
        'bank_name' => 'Bank of Maldives',
        'bank_account' => '7730000123456',
        'bank_account_name' => 'Original Name Pvt Ltd',
    ])->assertOk()
        ->assertJsonPath('data.bank_name', 'Bank of Maldives')
        ->assertJsonPath('data.bank_account', '7730000123456')
        ->assertJsonPath('data.bank_account_name', 'Original Name Pvt Ltd');

    // A partial identity mismatches every payment: all three are required.
    $this->patchJson('/api/merchant/bank-account', [
        'bank_name' => 'MIB',
        'bank_account' => '9990000',
    ])->assertUnprocessable();

    expect($this->merchant->refresh()->bank_name)->toBe('Bank of Maldives');
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
