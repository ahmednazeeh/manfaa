<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
});

/**
 * A blank draft store with its owner logged in — the state right after
 * self-signup, before any wizard step.
 */
function wizardOwner(array $merchantAttributes = []): MerchantUser
{
    $merchant = Merchant::factory()->draft()->create([
        'category' => null,
        'eligibility_basis' => null,
        'channel' => 'in_store',
        'setup_state' => [],
        ...$merchantAttributes,
    ]);

    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    test()->actingAs($owner, 'merchant');

    return $owner;
}

it('walks the wizard: profile, rate, submit — and resumes mid-way from GET setup', function () {
    $owner = wizardOwner();
    $merchant = $owner->merchant;

    // Fresh state: nothing done, curated categories offered for the picker.
    $state = $this->getJson('/api/merchant/setup')->assertOk()->json('data');
    expect($state['status'])->toBe('draft')
        ->and($state['steps'])->toBe(['profile' => false, 'logo' => false, 'rate' => false])
        ->and($state['values']['category'])->toBeNull()
        ->and($state['values']['rate_bp'])->toBeNull()
        ->and(collect($state['categories'])->pluck('slug'))->toContain('grocery', 'restaurant', 'other')
        ->and($state['categories'][0])->toHaveKeys(['slug', 'name_en', 'name_dv'])
        ->and($state['rate_bounds'])->toBe(['min_bp' => 50, 'max_bp' => 1000]);

    // Step 1: profile.
    $this->patchJson('/api/merchant/setup/profile', [
        'category' => 'grocery',
        'channel' => 'both',
        'eligibility_basis' => 'Full invoice total excluding delivery.',
        'contact_email' => 'shop@example.mv',
    ])->assertOk()
        ->assertJsonPath('data.steps.profile', true)
        ->assertJsonPath('data.values.category', 'grocery')
        ->assertJsonPath('data.values.channel', 'both');

    // Quit here — a new GET (fresh login) resumes with the partial state.
    $resumed = $this->getJson('/api/merchant/setup')->assertOk()->json('data');
    expect($resumed['steps'])->toBe(['profile' => true, 'logo' => false, 'rate' => false])
        ->and($resumed['values']['eligibility_basis'])->toBe('Full invoice total excluding delivery.')
        ->and($resumed['values']['rate_bp'])->toBeNull();

    // Step 2: the initial rate, effective immediately.
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 200])
        ->assertOk()
        ->assertJsonPath('data.steps.rate', true)
        ->assertJsonPath('data.values.rate_bp', 200);

    $rates = MerchantRate::query()->where('merchant_id', $merchant->id)->get();
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->rate_bp)->toBe(200)
        ->and($rates[0]->effective_to)->toBeNull()
        ->and($rates[0]->created_by)->toBe($owner->id);

    // Submit: draft -> pending_review (logo was optional).
    $this->postJson('/api/merchant/setup/submit')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_review');

    $merchant->refresh();
    expect($merchant->status)->toBe('pending_review')
        ->and($merchant->submitted_at)->not->toBeNull();

    // Still publicly invisible while pending.
    expect($this->getJson('/api/discover/merchants')->assertOk()->json('meta.total'))->toBe(0);
});

it('re-picking the rate before launch REPLACES the never-used initial row', function () {
    wizardOwner();

    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 200])->assertOk();
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 350])->assertOk();

    $rates = MerchantRate::query()->get();
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->rate_bp)->toBe(350);
});

it('bounds the rate by the ACTIVE fee tier schedule ceiling, not just the structural cap', function () {
    wizardOwner();

    // Structural violations fail validation outright.
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 30])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 2500])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 199.5])->assertUnprocessable();

    // Inside the structural cap but above the seeded schedule's 1000 bp
    // ceiling: refused with the published rate_not_priced code (§4 note).
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 1200])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'rate_not_priced');

    expect(MerchantRate::query()->count())->toBe(0);

    // The ceiling itself is sellable.
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 1000])->assertOk();
});

it('refuses submit while required pieces are missing, naming each one', function () {
    wizardOwner();

    $this->postJson('/api/merchant/setup/submit')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'setup_incomplete')
        ->assertJsonPath('missing', ['category', 'rate', 'terms']);

    // Whitespace-only terms do not count.
    $this->patchJson('/api/merchant/setup/profile', [
        'category' => 'cafe',
        'eligibility_basis' => '   ',
    ])->assertOk();

    $this->postJson('/api/merchant/setup/submit')
        ->assertUnprocessable()
        ->assertJsonPath('missing', ['rate', 'terms']);
});

it('refuses a submit whose picked category has since been deactivated', function () {
    $owner = wizardOwner();
    $owner->merchant->update(['category' => 'beauty', 'eligibility_basis' => 'Everything.']);
    MerchantRate::factory()->for($owner->merchant)->create(['rate_bp' => 200, 'effective_from' => now()->subMinute()]);

    StoreCategory::query()->where('slug', 'beauty')->update(['active' => false]);

    $this->postJson('/api/merchant/setup/submit')
        ->assertUnprocessable()
        ->assertJsonPath('missing', ['category']);
});

it('validates the profile step against the curated list and the channel enum', function () {
    wizardOwner();

    $this->patchJson('/api/merchant/setup/profile', ['category' => 'freeform text'])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/profile', ['channel' => 'is_online'])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/profile', ['channel' => 'online'])->assertOk();
});

it('locks every wizard write while the store is pending review', function () {
    $owner = wizardOwner();
    $owner->merchant->update(['status' => 'pending_review', 'submitted_at' => now()]);

    $this->patchJson('/api/merchant/setup/profile', ['category' => 'grocery'])
        ->assertStatus(409)->assertJsonPath('code', 'setup_not_editable');
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 200])
        ->assertStatus(409)->assertJsonPath('code', 'setup_not_editable');
    $this->postJson('/api/merchant/setup/submit')
        ->assertStatus(409)->assertJsonPath('code', 'setup_not_editable');

    // Reading the state stays allowed — the waiting screen renders from it.
    $this->getJson('/api/merchant/setup')->assertOk()
        ->assertJsonPath('data.status', 'pending_review');
});

it('keeps the wizard owner-only', function () {
    $owner = wizardOwner();
    $staff = MerchantUser::factory()->for($owner->merchant)->create(['role' => 'staff']);

    $this->actingAs($staff, 'merchant');

    $this->getJson('/api/merchant/setup')->assertForbidden()->assertJsonPath('code', 'owner_required');
    $this->patchJson('/api/merchant/setup/profile', ['category' => 'grocery'])->assertForbidden();
});

it('requires authentication for every setup route', function () {
    $this->getJson('/api/merchant/setup')->assertUnauthorized();
    $this->postJson('/api/merchant/setup/submit')->assertUnauthorized();
});
