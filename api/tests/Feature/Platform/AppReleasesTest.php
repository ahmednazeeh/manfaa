<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The admin-editable mobile release gates, and the promise that matters
 * most: the public /api/mobile/v1/config the Flutter apps parse keeps its
 * exact shape — env defaults until an admin saves, overrides afterwards.
 */
beforeEach(function () {
    $this->admin = AdminUser::factory()->create(['role' => 'admin']);
});

/** A full valid flag set: every app config/mobile.php declares, both platforms. */
function releasePayload(array $overrides = []): array
{
    $payload = [];

    foreach (array_keys((array) config('mobile')) as $app) {
        foreach (['ios', 'android'] as $platform) {
            $payload[$app][$platform] = [
                'minimum_build' => 5,
                'latest_build' => 9,
                'store_url' => "https://stores.example/{$app}/{$platform}",
            ];
        }
    }

    return array_replace_recursive($payload, $overrides);
}

it('lists the env-backed defaults until an admin saves', function () {
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/platform/app-releases')
        ->assertOk()
        // Mirrors config/mobile.php rather than hardcoding: the test box
        // may set these env values, production certainly does.
        ->assertJsonPath('data.customer.ios.minimum_build', (int) config('mobile.customer.ios.minimum_build'))
        ->assertJsonPath('data.customer.android.latest_build', (int) config('mobile.customer.android.latest_build'))
        ->assertJsonPath('data.merchant.android.minimum_build', (int) config('mobile.merchant.android.minimum_build'));
});

it('saves the full flag set and answers it back', function () {
    $this->actingAs($this->admin, 'admin')
        ->putJson('/api/admin/platform/app-releases', releasePayload())
        ->assertOk()
        ->assertJsonPath('data.customer.android.minimum_build', 5)
        ->assertJsonPath('data.customer.android.latest_build', 9)
        ->assertJsonPath('data.customer.android.store_url', 'https://stores.example/customer/android')
        ->assertJsonPath('data.merchant.ios.minimum_build', 5);

    // Stored under PlatformConfig-style keys, one row per value, with the
    // author on the row.
    $row = PlatformSetting::query()->where('key', 'mobile.customer.android.minimum_build')->sole();

    expect($row->value)->toBe(5)
        ->and($row->updated_by)->toBe($this->admin->id);
});

it('serves the saved override on the public config, immediately and typed', function () {
    // Prime the cache with the env defaults first, so this also proves the
    // save BUSTS it — a stale minimum_build is a bad build still talking.
    $before = $this->getJson('/api/mobile/v1/config')->assertOk();

    expect($before->json('data.apps.customer.android.minimum_build'))
        ->toBe((int) config('mobile.customer.android.minimum_build'));

    $this->actingAs($this->admin, 'admin')
        ->putJson('/api/admin/platform/app-releases', releasePayload())
        ->assertOk();

    $after = $this->getJson('/api/mobile/v1/config')->assertOk();

    // The exact contract the installed apps parse: ints for the builds, a
    // plain string for the URL.
    expect($after->json('data.apps.customer.android.minimum_build'))->toBe(5)
        ->and($after->json('data.apps.customer.android.latest_build'))->toBe(9)
        ->and($after->json('data.apps.customer.android.store_url'))->toBe('https://stores.example/customer/android')
        ->and($after->json('data.apps.merchant.ios.minimum_build'))->toBe(5);
});

it('keeps store_url a string on the public config even when cleared', function () {
    $payload = releasePayload();
    $payload['customer']['android']['store_url'] = null;

    $this->actingAs($this->admin, 'admin')
        ->putJson('/api/admin/platform/app-releases', $payload)
        ->assertOk()
        // The admin surface says null — nothing is set.
        ->assertJsonPath('data.customer.android.store_url', null);

    // The apps were shipped against '' and must keep getting it.
    expect($this->getJson('/api/mobile/v1/config')->json('data.apps.customer.android.store_url'))
        ->toBe('');
});

it('refuses a latest build below the minimum', function () {
    $this->actingAs($this->admin, 'admin')
        ->putJson('/api/admin/platform/app-releases', releasePayload([
            'customer' => ['android' => ['minimum_build' => 10, 'latest_build' => 9]],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer.android.latest_build');
});

it('refuses builds below one and non-integer builds', function () {
    $this->actingAs($this->admin, 'admin')
        ->putJson('/api/admin/platform/app-releases', releasePayload([
            'customer' => ['ios' => ['minimum_build' => 0]],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer.ios.minimum_build');

    $this->actingAs($this->admin, 'admin')
        ->putJson('/api/admin/platform/app-releases', releasePayload([
            'merchant' => ['android' => ['latest_build' => 'ten']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('merchant.android.latest_build');
});

it('refuses a store_url that is not a url', function () {
    $this->actingAs($this->admin, 'admin')
        ->putJson('/api/admin/platform/app-releases', releasePayload([
            'customer' => ['android' => ['store_url' => 'not a url']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer.android.store_url');
});

it('requires an admin', function () {
    $this->getJson('/api/admin/platform/app-releases')->assertStatus(401);
    $this->putJson('/api/admin/platform/app-releases', releasePayload())->assertStatus(401);
});
