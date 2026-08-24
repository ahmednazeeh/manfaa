<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The panel talks through Sanctum's stateful pipeline — session, queued
// cookies and all — which only engages for a stateful Referer.
beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
});

/*
 * The merchant panel's root fork reads `manfaa-auth` to decide landing vs
 * dashboard. The marker must exist ONLY for people who actually signed in
 * (owner report, 2026-08-24: forking on manfaa-sid trapped anonymous
 * visitors in a redirect loop, because the CSRF bootstrap mints a session
 * for anyone who opens the login form).
 */

function markerLogin(): MerchantUser
{
    $merchant = Merchant::factory()->create();

    return MerchantUser::factory()->for($merchant)->owner()->create([
        'email' => 'owner@shop.mv',
        'password' => Hash::make('secret-123'),
    ]);
}

it('sets the auth marker on a real login and clears it on logout', function () {
    markerLogin();

    $this->postJson('/api/merchant/auth/login', [
        'email' => 'owner@shop.mv',
        'password' => 'secret-123',
    ])->assertOk()->assertCookie('manfaa-auth');

    $this->postJson('/api/merchant/auth/logout')
        ->assertNoContent()
        ->assertCookieExpired('manfaa-auth');
});

it('refreshes the marker on an authenticated me read', function () {
    $user = markerLogin();

    $this->actingAs($user, 'merchant')
        ->getJson('/api/merchant/auth/me')
        ->assertOk()
        ->assertCookie('manfaa-auth');
});

it('never hands the marker to a failed login or the CSRF bootstrap', function () {
    markerLogin();

    $failed = $this->postJson('/api/merchant/auth/login', [
        'email' => 'owner@shop.mv',
        'password' => 'wrong',
    ])->assertStatus(422);

    expect(collect($failed->headers->getCookies())->pluck('name'))
        ->not->toContain('manfaa-auth');

    $csrf = $this->get('/sanctum/csrf-cookie');

    expect(collect($csrf->headers->getCookies())->pluck('name'))
        ->not->toContain('manfaa-auth');
});
