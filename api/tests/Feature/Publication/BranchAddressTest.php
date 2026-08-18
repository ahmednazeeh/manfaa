<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Branch addresses are required now, and an admin may correct one (owner
 * round 2026-08-18).
 *
 * The requirement exists because a pin alone reads as a coordinate on every
 * map we hand off to; the reverse-geocode helper exists so meeting the
 * requirement is a tap rather than typing.
 */
beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

it('refuses a new branch with no address', function () {
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/branches', ['name' => 'Hulhumalé'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('address');
});

it('refuses to blank an address that is already there', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create();

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/branches/{$branch->id}", ['address' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('address');
});

it('turns a dropped pin into a written address', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
        'address' => [
            'road' => 'Majeedhee Magu',
            'suburb' => 'Henveiru',
            'city' => 'Malé',
            'country' => 'Maldives',
            'postcode' => '20042',
        ],
    ])]);

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/branches/reverse-geocode?lat=4.1755&lng=73.5093')
        ->assertOk()
        // Road, ward, city — what a person would say out loud. Not
        // Nominatim's paragraph, which trails country and postcode.
        ->assertJsonPath('data.address', 'Majeedhee Magu, Henveiru, Malé');
});

it('answers with a null address rather than an error when the geocoder is down', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/branches/reverse-geocode?lat=4.1755&lng=73.5093')
        ->assertOk()
        ->assertJsonPath('data.address', null);
});

it('lets an admin correct a branch address', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create([
        'address' => 'Wrong Magu',
    ]);
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$this->merchant->id}/branches/{$branch->id}", [
            'address' => 'Majeedhee Magu, Henveiru, Malé',
        ])
        ->assertOk()
        ->assertJsonPath('data.address', 'Majeedhee Magu, Henveiru, Malé');

    expect($branch->fresh()->address)->toBe('Majeedhee Magu, Henveiru, Malé');
});

it('refuses an admin correction that leaves half a coordinate', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create();
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$this->merchant->id}/branches/{$branch->id}", [
            'lat' => null,
        ])
        ->assertUnprocessable();
});

it('will not let an admin edit a branch belonging to another store', function () {
    $other = Merchant::factory()->create();
    $branch = MerchantBranch::factory()->for($other)->create();
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/merchants/{$this->merchant->id}/branches/{$branch->id}", [
            'address' => 'Nowhere',
        ])
        ->assertNotFound();
});
