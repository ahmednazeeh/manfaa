<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
});

it('starts unset: admins read null and the public theme carries null', function () {
    $this->actingAs(AdminUser::factory()->create(['role' => 'admin']), 'admin')
        ->getJson('/api/admin/platform/brand')
        ->assertOk()
        ->assertJsonPath('data.color', null);

    $this->getJson('/api/theme')
        ->assertOk()
        ->assertJsonPath('data.brand', null);
});

it('a superadmin sets the colour and the public theme serves it immediately', function () {
    // Warm the caches first so green proves the bust, not a cold read.
    $this->getJson('/api/theme')->assertOk();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->putJson('/api/admin/platform/brand', ['color' => '#E11D48'])
        ->assertOk()
        ->assertJsonPath('data.color', '#e11d48');

    $this->getJson('/api/theme')
        ->assertOk()
        ->assertJsonPath('data.brand', '#e11d48');

    // Clearing returns the storefront to its built-in hue.
    $this->putJson('/api/admin/platform/brand', ['color' => null])
        ->assertOk()
        ->assertJsonPath('data.color', null);

    $this->getJson('/api/theme')->assertJsonPath('data.brand', null);
});

it('refuses junk and the wrong rank', function () {
    $superadmin = AdminUser::factory()->create(['role' => 'superadmin']);

    foreach (['red', '#fff', '#gggggg', '#12345', 'e11d48'] as $bad) {
        $this->actingAs($superadmin, 'admin')
            ->putJson('/api/admin/platform/brand', ['color' => $bad])
            ->assertUnprocessable();
    }

    $this->actingAs(AdminUser::factory()->create(['role' => 'admin']), 'admin')
        ->putJson('/api/admin/platform/brand', ['color' => '#e11d48'])
        ->assertForbidden();
});

it('refuses guests outright', function () {
    $this->putJson('/api/admin/platform/brand', ['color' => '#e11d48'])
        ->assertUnauthorized();
});
