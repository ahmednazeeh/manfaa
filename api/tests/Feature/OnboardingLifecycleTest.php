<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\AdminUser;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The single §1 self-signup journey, end to end over real HTTP with nothing
 * seeded by hand except the reviewing admin: OTP signup → register →
 * resume-after-logout mid-wizard → complete → submit → admin REJECT with a
 * reason → owner edits → resubmit → approve → the store appears in the
 * public directory carrying its channel and curated category — while a
 * sibling store still in pending_review stays invisible everywhere public.
 *
 * Piecewise coverage lives in tests/Feature/Onboarding/*; this test proves
 * the pieces meet across logout boundaries and the rejection loop.
 */
it('runs signup → resume → submit → reject → resubmit → approve → public listing, pending sibling invisible', function () {
    $this->withHeader('Referer', 'http://localhost');

    $sms = new class implements SmsSender
    {
        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $phone, string $message): void
        {
            $this->sent[] = ['phone' => $phone, 'message' => $message];
        }
    };
    $this->app->instance(SmsSender::class, $sms);

    $signupToken = function (string $phone) use ($sms): string {
        $this->postJson('/api/merchant/signup/request-otp', ['phone' => $phone])->assertOk();

        $mine = array_values(array_filter($sms->sent, fn (array $m): bool => $m['phone'] === $phone));
        expect($mine)->not->toBeEmpty();
        preg_match('/\b(\d{6})\b/', end($mine)['message'], $matches);

        return $this->postJson('/api/merchant/signup/verify-otp', [
            'phone' => $phone,
            'code' => $matches[1],
        ])->assertOk()->json('data.signup_token');
    };

    // --- Signup: OTP → register creates a DRAFT store, owner logged in ----
    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => $signupToken('+9607701001'),
        'business_name' => 'Reef Corner',
        'email' => 'owner@reefcorner.mv',
        'password' => 'reef-corner-secret-1',
    ])->assertCreated()
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.merchant.status', 'draft');

    // --- Mid-wizard: only the profile step, then quit ---------------------
    $this->patchJson('/api/merchant/setup/profile', [
        'category' => 'grocery',
        'channel' => 'both',
        'eligibility_basis' => 'Full invoice total excluding delivery.',
    ])->assertOk()->assertJsonPath('data.steps.profile', true);

    $this->postJson('/api/merchant/auth/logout')->assertNoContent();
    $this->getJson('/api/merchant/setup')->assertUnauthorized();

    // --- Resume: a fresh login lands back on the half-done wizard ---------
    $this->postJson('/api/merchant/auth/login', [
        'email' => 'owner@reefcorner.mv',
        'password' => 'reef-corner-secret-1',
    ])->assertOk();

    $resumed = $this->getJson('/api/merchant/setup')->assertOk()->json('data');
    expect($resumed['status'])->toBe('draft')
        ->and($resumed['steps'])->toBe(['profile' => true, 'logo' => false, 'rate' => false])
        ->and($resumed['values']['category'])->toBe('grocery')
        ->and($resumed['values']['channel'])->toBe('both')
        ->and($resumed['values']['rate_bp'])->toBeNull();

    // --- Complete and submit → pending_review, still publicly invisible ---
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 200])->assertOk();
    $this->postJson('/api/merchant/setup/submit')->assertOk()
        ->assertJsonPath('data.status', 'pending_review');

    expect($this->getJson('/api/discover/merchants')->assertOk()->json('meta.total'))->toBe(0);
    $this->getJson('/api/discover/merchants/reef-corner')->assertNotFound();

    // --- A sibling store signs up and submits too — it will stay pending --
    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => $signupToken('+9607701002'),
        'business_name' => 'Lagoon Sibling',
        'email' => 'owner@lagoonsibling.mv',
        'password' => 'lagoon-sibling-secret',
    ])->assertCreated();

    $this->patchJson('/api/merchant/setup/profile', [
        'category' => 'restaurant',
        'channel' => 'in_store',
        'eligibility_basis' => 'Food and beverage only.',
    ])->assertOk();
    $this->patchJson('/api/merchant/setup/rate', ['rate_bp' => 100])->assertOk();
    $this->postJson('/api/merchant/setup/submit')->assertOk()
        ->assertJsonPath('data.status', 'pending_review');

    // --- Admin rejects Reef Corner, with the reason the owner will see ----
    $reef = Merchant::query()->where('slug', 'reef-corner')->firstOrFail();
    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    $this->postJson("/api/admin/store-reviews/{$reef->id}/reject", [
        'reason' => 'Eligibility terms too vague — name the exclusions.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');

    // --- The owner logs back in, sees the reason, edits, resubmits --------
    $this->postJson('/api/merchant/auth/login', [
        'email' => 'owner@reefcorner.mv',
        'password' => 'reef-corner-secret-1',
    ])->assertOk();

    $this->getJson('/api/merchant/setup')->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejected_reason', 'Eligibility terms too vague — name the exclusions.');

    $this->patchJson('/api/merchant/setup/profile', [
        'eligibility_basis' => 'Full invoice total excluding delivery and service charge.',
    ])->assertOk();

    $this->postJson('/api/merchant/setup/submit')->assertOk()
        ->assertJsonPath('data.status', 'pending_review')
        ->assertJsonPath('data.rejected_reason', null);

    // --- Approval flips it live and busts the public caches ---------------
    $this->postJson("/api/admin/store-reviews/{$reef->id}/approve")->assertOk()
        ->assertJsonPath('data.status', 'active');

    // --- Public directory: the approved store with channel + category -----
    $directory = $this->getJson('/api/discover/merchants')->assertOk();
    expect($directory->json('meta.total'))->toBe(1)
        ->and($directory->json('data.0.slug'))->toBe('reef-corner')
        ->and($directory->json('data.0.channel'))->toBe('both')
        ->and($directory->json('data.0.category'))->toBe('grocery');

    $this->getJson('/api/discover/merchants/reef-corner')->assertOk()
        ->assertJsonPath('data.channel', 'both')
        ->assertJsonPath('data.category', 'grocery')
        ->assertJsonPath('data.rate_bp', 200)
        ->assertJsonPath('data.cashback_basis', 'Full invoice total excluding delivery and service charge.');

    // --- The pending sibling stays invisible — its 404 is indistinguishable
    // from a slug that never existed.
    expect(collect($directory->json('data'))->pluck('slug'))->not->toContain('lagoon-sibling');

    // (Testing runs with APP_DEBUG, so the bodies carry stack traces that
    // differ by call-site line; compare the semantic payload clients see.)
    $siblingMiss = $this->getJson('/api/discover/merchants/lagoon-sibling')->assertNotFound();
    $neverExisted = $this->getJson('/api/discover/merchants/no-such-store')->assertNotFound();
    expect($siblingMiss->json('message'))->toBe($neverExisted->json('message'));
});
