<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantBranch;
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
        ->and($state['steps'])->toBe(['profile' => false, 'location' => false, 'logo' => false, 'rate' => false])
        ->and($state['values']['category'])->toBeNull()
        ->and($state['values']['cashback_rate_percent'])->toBeNull()
        ->and(collect($state['categories'])->pluck('slug'))->toContain('grocery', 'restaurant', 'other')
        ->and($state['categories'][0])->toHaveKeys(['slug', 'name_en', 'name_dv'])
        ->and($state['rate_bounds'])->toBe(['min_percent' => '0.50', 'max_percent' => '10.00']);

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
    expect($resumed['steps'])->toBe(['profile' => true, 'location' => false, 'logo' => false, 'rate' => false])
        ->and($resumed['values']['eligibility_basis'])->toBe('Full invoice total excluding delivery.')
        ->and($resumed['values']['cashback_rate_percent'])->toBeNull();

    // Step 2: the initial rate, effective immediately.
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '2.00'])
        ->assertOk()
        ->assertJsonPath('data.steps.rate', true)
        ->assertJsonPath('data.values.cashback_rate_percent', '2.00');

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

    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '2.00'])->assertOk();
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '3.50'])->assertOk();

    $rates = MerchantRate::query()->get();
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->rate_bp)->toBe(350);
});

it('bounds the rate by the ACTIVE fee tier schedule ceiling, not just the structural cap', function () {
    wizardOwner();

    // Structural violations fail validation outright.
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '0.30'])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '25.00'])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '1.995'])->assertUnprocessable();

    // Inside the structural cap but above the seeded schedule's 1000 bp
    // ceiling: refused with the published rate_not_priced code (§4 note).
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '12.00'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'rate_not_priced');

    expect(MerchantRate::query()->count())->toBe(0);

    // The ceiling itself is sellable.
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '10.00'])->assertOk();
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

it('refuses submit for a store with no phone number at all', function () {
    // The factory gives every fixture a contact number, because every real
    // store signs up with one — a phoneless store is the explicit case, and
    // it is exactly the store the customer app would show with no number.
    wizardOwner(['contact_phone' => null, 'support_phone' => null, 'category' => 'cafe', 'eligibility_basis' => 'Everything.']);

    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '2.00'])->assertOk();

    $this->postJson('/api/merchant/setup/submit')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'setup_incomplete')
        ->assertJsonPath('missing', ['contact']);

    // Either number satisfies the gate; the model hook then keeps the other
    // side materialised from it on later writes.
    $this->patchJson('/api/merchant/setup/profile', ['contact_phone' => '+9607001122'])->assertOk();
    $this->postJson('/api/merchant/setup/submit')->assertOk();
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

it('pins the primary branch, creating it once and MOVING it on every later visit', function () {
    $owner = wizardOwner(['name' => 'Reef Mart']);

    $this->patchJson('/api/merchant/setup/location', ['lat' => 4.1755, 'lng' => 73.5093])
        ->assertOk()
        ->assertJsonPath('data.steps.location', true)
        // No branch name is asked for in the wizard: the first pin takes the
        // store's own name and settings can rename it later (D15).
        ->assertJsonPath('data.values.primary_branch.name', 'Reef Mart')
        ->assertJsonPath('data.values.primary_branch.lat', 4.1755)
        ->assertJsonPath('data.values.primary_branch.lng', 73.5093);

    // Walking back through the step is one click on the step indicator, and
    // the owner does it on every resume. The pin moves; the branch count
    // does not.
    $this->patchJson('/api/merchant/setup/location', ['lat' => 4.2, 'lng' => 73.6])->assertOk();

    $branches = MerchantBranch::query()->where('merchant_id', $owner->merchant->id)->get();
    expect($branches)->toHaveCount(1)
        ->and((float) $branches[0]->lat)->toBe(4.2)
        ->and((float) $branches[0]->lng)->toBe(73.6);
});

it('moves the LOWEST-id branch — the primary one — and leaves the rest alone', function () {
    $owner = wizardOwner();
    $primary = MerchantBranch::factory()->for($owner->merchant)->create(['lat' => null, 'lng' => null]);
    $other = MerchantBranch::factory()->for($owner->merchant)->create(['lat' => 4.3, 'lng' => 73.4]);

    $this->patchJson('/api/merchant/setup/location', ['lat' => 4.1755, 'lng' => 73.5093])->assertOk();

    expect((float) $primary->refresh()->lat)->toBe(4.1755)
        ->and((float) $other->refresh()->lat)->toBe(4.3)
        ->and(MerchantBranch::query()->count())->toBe(2);
});

it('requires a complete, bounded coordinate pair — this step exists to set a pin', function () {
    wizardOwner();

    $this->patchJson('/api/merchant/setup/location', ['lat' => 4.1755])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/location', ['lng' => 73.5093])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/location', ['lat' => 91, 'lng' => 73.5093])->assertUnprocessable();
    $this->patchJson('/api/merchant/setup/location', ['lat' => 4.1755, 'lng' => 181])->assertUnprocessable();

    expect(MerchantBranch::query()->count())->toBe(0);
});

it('locks every wizard write while the store is pending review', function () {
    $owner = wizardOwner();
    $owner->merchant->update(['status' => 'pending_review', 'submitted_at' => now()]);

    $this->patchJson('/api/merchant/setup/profile', ['category' => 'grocery'])
        ->assertStatus(409)->assertJsonPath('code', 'setup_not_editable');
    $this->patchJson('/api/merchant/setup/location', ['lat' => 4.1755, 'lng' => 73.5093])
        ->assertStatus(409)->assertJsonPath('code', 'setup_not_editable');
    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '2.00'])
        ->assertStatus(409)->assertJsonPath('code', 'setup_not_editable');
    $this->postJson('/api/merchant/setup/submit')
        ->assertStatus(409)->assertJsonPath('code', 'setup_not_editable');

    // Reading the state stays allowed — the waiting screen renders from it.
    $this->getJson('/api/merchant/setup')->assertOk()
        ->assertJsonPath('data.status', 'pending_review');
});

it('keeps the wizard behind the setup permissions', function () {
    $owner = wizardOwner();
    $staff = MerchantUser::factory()->for($owner->merchant)->staff()->create();

    $this->actingAs($staff, 'merchant');

    $this->getJson('/api/merchant/setup')->assertForbidden()
        ->assertJsonPath('code', 'permission_required')
        ->assertJsonPath('permission', 'setup.view');
    $this->patchJson('/api/merchant/setup/profile', ['category' => 'grocery'])->assertForbidden();
});

it('requires authentication for every setup route', function () {
    $this->getJson('/api/merchant/setup')->assertUnauthorized();
    $this->postJson('/api/merchant/setup/submit')->assertUnauthorized();
});

it('requires the store to describe itself before review, and holds the description to 180 words', function () {
    $owner = wizardOwner([
        'category' => 'cafe',
        'eligibility_basis' => 'Everything.',
        'description' => null,
    ]);
    MerchantRate::factory()->for($owner->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subMinute(),
    ]);

    // A store with no words about itself cannot go live.
    $this->postJson('/api/merchant/setup/submit')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'setup_incomplete')
        ->assertJsonPath('missing', ['description']);

    // 181 words is refused; 180 is accepted — a WORD ceiling, so a long
    // Dhivehi word costs the same as a short English one.
    $this->patchJson('/api/merchant/setup/profile', [
        'description' => implode(' ', array_fill(0, 181, 'word')),
    ])->assertUnprocessable();

    $this->patchJson('/api/merchant/setup/profile', [
        'description' => implode(' ', array_fill(0, 180, 'word')),
    ])->assertOk();

    $this->postJson('/api/merchant/setup/submit')->assertOk();

    expect($owner->merchant->fresh()->description)->toContain('word');
});

it('takes Dhivehi as OPTIONAL beside the required English description', function () {
    $owner = wizardOwner([
        'category' => 'cafe',
        'eligibility_basis' => 'Everything.',
        'description' => null,
    ]);
    MerchantRate::factory()->for($owner->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subMinute(),
    ]);

    // Dhivehi alone does not satisfy the requirement — English is the one
    // every store must write (owner decision 2026-08-18).
    $this->patchJson('/api/merchant/setup/profile', [
        'description_dv' => 'ދިވެހި ތަފްޞީލު',
    ])->assertOk();

    $this->postJson('/api/merchant/setup/submit')
        ->assertUnprocessable()
        ->assertJsonPath('missing', ['description']);

    // English fills the gap; the Dhivehi it was given stands beside it.
    $this->patchJson('/api/merchant/setup/profile', [
        'description' => 'A harbour cafe serving coffee and short eats.',
    ])->assertOk();

    $this->postJson('/api/merchant/setup/submit')->assertOk();

    $merchant = $owner->merchant->fresh();
    expect($merchant->description)->toContain('harbour cafe')
        ->and($merchant->description_dv)->toBe('ދިވެހި ތަފްޞީލު');

    // And the Dhivehi side honours the same 180-word ceiling.
    $owner->merchant->update(['status' => 'draft']);
    $this->patchJson('/api/merchant/setup/profile', [
        'description_dv' => implode(' ', array_fill(0, 181, 'ބަސް')),
    ])->assertUnprocessable();
});
