<?php

declare(strict_types=1);

use App\Domain\Credentials\CredentialService;
use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Merchant SELF-SERVE credential issuance (§13b task #21). The admin path
 * is covered by IssueCredentialTest / RevokeCredentialTest; this suite is
 * about the owner minting their own vendor token from the panel:
 *
 *   - the plaintext token exists exactly once, in the 201 body, and the
 *     token it names really works against /v1;
 *   - the tier gate (owner only) and the store-status gates hold;
 *   - a token can never exceed the abilities granted to it;
 *   - revocation is immediate, scoped to the merchant's own credentials,
 *     and never touches a sibling;
 *   - issuance is bounded twice — 5/hour per merchant, 10 live credentials.
 */

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

/**
 * A bare bearer request: drops any cached guard user and the sticky
 * Authorization default so the token — and only the token — authenticates.
 */
function asVendor(string $plainTextToken): TestCase
{
    app('auth')->forgetGuards();
    test()->flushHeaders();

    return test()->withHeader('Authorization', 'Bearer '.$plainTextToken);
}

/**
 * @param  list<string>  $abilities
 */
function selfIssue(string $label = 'TillPoint POS', array $abilities = ['transactions:write']): TestResponse
{
    app('auth')->forgetGuards();
    test()->flushHeaders();

    return test()->actingAs(test()->owner, 'merchant')
        ->postJson('/api/merchant/credentials', ['label' => $label, 'abilities' => $abilities]);
}

it('lets an owner issue a credential: plaintext once, audited to the merchant user, and it works against /v1', function () {
    $this->seed(LedgerAccountSeeder::class);
    Customer::factory()->create(['customer_code' => '482917']);

    $response = selfIssue('TillPoint POS', ['transactions:write', 'rates:read'])
        ->assertCreated()
        ->assertJsonPath('credential.merchant_id', $this->merchant->id)
        ->assertJsonPath('credential.label', 'TillPoint POS')
        ->assertJsonPath('credential.display_name', 'TillPoint POS')
        // No pos_vendors row: the registry is admin-curated and a merchant
        // must not be able to write into it.
        ->assertJsonPath('credential.pos_vendor', null)
        ->assertJsonPath('credential.abilities', ['transactions:write', 'rates:read'])
        // The audit distinguishes a merchant-minted token from ours.
        ->assertJsonPath('credential.issued_by', null)
        ->assertJsonPath('credential.issuer.type', 'merchant_user')
        ->assertJsonPath('credential.issuer.name', $this->owner->name)
        ->assertJsonPath('credential.revoked_at', null);

    $plain = $response->json('plaintext_token');
    expect($plain)->toBeString()->toContain('|');

    $secret = explode('|', $plain, 2)[1];
    $credential = ApiCredential::query()->sole();
    expect((int) $credential->issued_by_merchant_user)->toBe($this->owner->id)
        ->and($credential->issued_by)->toBeNull()
        ->and($credential->label)->toBe('TillPoint POS')
        // Only the digest is stored — the plaintext is unrecoverable.
        ->and($credential->token_hash)->toBe(hash('sha256', $secret));

    // EXACTLY once: the listing carries metadata and nothing else.
    $listing = $this->getJson('/api/merchant/credentials')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.display_name', 'TillPoint POS');

    expect($listing->getContent())
        ->not->toContain($secret)
        ->not->toContain($credential->token_hash)
        ->not->toContain('token_hash')
        ->not->toContain('plaintext');

    // The token is a real vendor credential: it reads the rate...
    asVendor($plain)->getJson('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('cashback_rate_percent', '2.00');

    // ...and records a sale, which is the whole point of the wizard.
    asVendor($plain)
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/transactions', [
            'invoice_no' => 'INV-SELF-1',
            'customer_ref' => '482917',
            'eligible_amount' => 118000,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('transaction.cashback_laari', 2360);

    // §9.1 last_used_at gives the panel its "last seen" column.
    expect($credential->refresh()->last_used_at)->not->toBeNull();
});

it('refuses issuance and revocation to managers and staff', function () {
    $issued = app(CredentialService::class)
        ->issueForMerchantUser($this->merchant, 'TillPoint POS', ['rates:read'], $this->owner);

    foreach (['manager', 'staff'] as $role) {
        $user = MerchantUser::factory()->for($this->merchant)->create(['role' => $role]);

        app('auth')->forgetGuards();
        $this->actingAs($user, 'merchant')
            ->postJson('/api/merchant/credentials', [
                'label' => 'Rogue POS',
                'abilities' => ['transactions:write'],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'owner_required');

        app('auth')->forgetGuards();
        $this->actingAs($user, 'merchant')
            ->deleteJson("/api/merchant/credentials/{$issued->credential->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'owner_required');
    }

    // Nothing was minted and nothing was killed.
    expect(ApiCredential::query()->count())->toBe(1)
        ->and($issued->credential->refresh()->revoked_at)->toBeNull();
});

it('mints a token that cannot exceed the abilities granted to it', function () {
    $plain = selfIssue('Read-only integration', ['rates:read'])
        ->assertCreated()
        ->assertJsonPath('credential.abilities', ['rates:read'])
        ->json('plaintext_token');

    $token = PersonalAccessToken::findToken($plain);
    expect($token)->not->toBeNull()
        ->and($token->tokenable)->toBeInstanceOf(Merchant::class)
        ->and($token->tokenable->id)->toBe($this->merchant->id)
        ->and($token->can('rates:read'))->toBeTrue()
        ->and($token->can('transactions:write'))->toBeFalse()
        ->and($token->can('transactions:reverse'))->toBeFalse()
        ->and($token->can('customers:lookup'))->toBeFalse();

    // The granted ability works; every ungranted one is 403 at the edge.
    asVendor($plain)->getJson('/api/v1/merchants/me/rate')->assertOk();

    asVendor($plain)
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/transactions', [
            'invoice_no' => 'INV-NOPE-1',
            'customer_ref' => '482917',
            'eligible_amount' => 118000,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertForbidden();

    asVendor($plain)->getJson('/api/v1/customers/lookup?ref=482917')->assertForbidden();
});

it('rejects abilities outside the closed vendor set, and an empty grant', function () {
    selfIssue('Everything POS', ['transactions:write', 'admin:everything'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('abilities.1');

    selfIssue('Nothing POS', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('abilities');

    selfIssue('', ['transactions:write'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');

    // Whitespace is not a partner name: the global TrimStrings +
    // ConvertEmptyStringsToNull pair turns it into a missing field rather
    // than a credential labelled "   ".
    selfIssue('   ', ['transactions:write'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');

    expect(ApiCredential::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('revokes one credential immediately, leaving the merchant\'s other credentials working', function () {
    $first = selfIssue('TillPoint POS', ['rates:read'])->assertCreated()->json('plaintext_token');
    $second = selfIssue('RetailSoft', ['rates:read'])->assertCreated()->json('plaintext_token');

    asVendor($first)->getJson('/api/v1/merchants/me/rate')->assertOk();
    asVendor($second)->getJson('/api/v1/merchants/me/rate')->assertOk();

    $firstCredential = ApiCredential::query()->where('label', 'TillPoint POS')->sole();

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->actingAs($this->owner, 'merchant')
        ->deleteJson("/api/merchant/credentials/{$firstCredential->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $firstCredential->id)
        ->assertJsonPath('data.revoked_by_type', 'merchant_user')
        ->assertJsonPath('data.revoked_by', null);

    $revoked = $firstCredential->refresh();
    expect($revoked->revoked_at)->not->toBeNull()
        ->and((int) $revoked->revoked_by_merchant_user)->toBe($this->owner->id)
        ->and($revoked->revoked_by)->toBeNull()
        // The Sanctum token is gone; the audit row survives with its linkage.
        ->and($revoked->personal_access_token_id)->not->toBeNull()
        ->and(PersonalAccessToken::query()->find($revoked->personal_access_token_id))->toBeNull();

    // Auth dies on the very next request; the sibling is untouched.
    asVendor($first)->getJson('/api/v1/merchants/me/rate')->assertUnauthorized();
    asVendor($second)->getJson('/api/v1/merchants/me/rate')->assertOk();

    // Double revocation is a 409 and never rewrites the original stamp.
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->actingAs($this->owner, 'merchant')
        ->deleteJson("/api/merchant/credentials/{$firstCredential->id}")
        ->assertConflict();
});

it('answers 404 when an owner tries to revoke another merchant\'s credential', function () {
    $mine = selfIssue('TillPoint POS', ['rates:read'])->assertCreated()->json('plaintext_token');
    $myCredential = ApiCredential::query()->sole();

    $otherMerchant = Merchant::factory()->create();
    $otherOwner = MerchantUser::factory()->for($otherMerchant)->owner()->create();

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->actingAs($otherOwner, 'merchant')
        ->deleteJson("/api/merchant/credentials/{$myCredential->id}")
        ->assertNotFound();

    // Unchanged, and still authenticating — a foreign id is no oracle and
    // no weapon.
    expect($myCredential->refresh()->revoked_at)->toBeNull();
    asVendor($mine)->getJson('/api/v1/merchants/me/rate')->assertOk();
});

it('rate-limits issuance to five per hour per merchant, counting successes only', function () {
    for ($i = 1; $i <= 5; $i++) {
        selfIssue("Partner {$i}", ['rates:read'])->assertCreated();
    }

    $refused = selfIssue('Partner 6', ['rates:read'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'issuance_rate_limited');

    expect($refused->headers->get('Retry-After'))->not->toBeNull()
        ->and(ApiCredential::query()->count())->toBe(5);

    // A SECOND merchant is unaffected — the budget is per store, and one
    // busy merchant never starves another.
    $otherMerchant = Merchant::factory()->create();
    $otherOwner = MerchantUser::factory()->for($otherMerchant)->owner()->create();

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->actingAs($otherOwner, 'merchant')
        ->postJson('/api/merchant/credentials', [
            'label' => 'Their POS',
            'abilities' => ['rates:read'],
        ])
        ->assertCreated();
});

it('caps live credentials per merchant with a 422 that revoking clears', function () {
    $service = app(CredentialService::class);

    // Straight through the domain, so the hourly limiter plays no part.
    for ($i = 1; $i <= CredentialService::MAX_ACTIVE_PER_MERCHANT; $i++) {
        $service->issueForMerchantUser($this->merchant, "Partner {$i}", ['rates:read'], $this->owner);
    }

    selfIssue('One too many', ['rates:read'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'credential_cap_reached');

    expect(ApiCredential::query()->whereNull('revoked_at')->count())
        ->toBe(CredentialService::MAX_ACTIVE_PER_MERCHANT);

    // Revoked credentials do not count against the cap: freeing one slot
    // makes room immediately.
    $stale = ApiCredential::query()->orderBy('id')->first();
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->actingAs($this->owner, 'merchant')
        ->deleteJson("/api/merchant/credentials/{$stale->id}")
        ->assertOk();

    selfIssue('Now there is room', ['rates:read'])->assertCreated();
});

it('refuses issuance for a store that is not approved, and for a suspended store', function () {
    foreach (['draft', 'pending_review', 'rejected'] as $status) {
        $merchant = Merchant::factory()->create(['status' => $status]);
        $owner = MerchantUser::factory()->for($merchant)->owner()->create();

        app('auth')->forgetGuards();
        $this->actingAs($owner, 'merchant')
            ->postJson('/api/merchant/credentials', [
                'label' => 'Too early POS',
                'abilities' => ['rates:read'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'store_not_approved');
    }

    // Suspended clears EnsureMerchantApproved (it is an APPROVED store) and
    // is refused inside the controller instead: it creates no cashback (§7),
    // so a fresh write credential would only mint ineligible traffic.
    $suspended = Merchant::factory()->suspended()->create();
    $suspendedOwner = MerchantUser::factory()->for($suspended)->owner()->create();

    app('auth')->forgetGuards();
    $this->actingAs($suspendedOwner, 'merchant')
        ->postJson('/api/merchant/credentials', [
            'label' => 'Suspended POS',
            'abilities' => ['rates:read'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_trading');

    expect(ApiCredential::query()->count())->toBe(0);

    // Reading and REVOKING stay open while suspended — killing a leaked
    // token must never depend on the store's commercial standing.
    $existing = app(CredentialService::class)
        ->issueForMerchantUser($suspended, 'Older POS', ['rates:read'], $suspendedOwner);

    app('auth')->forgetGuards();
    $this->actingAs($suspendedOwner, 'merchant')
        ->getJson('/api/merchant/credentials')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    app('auth')->forgetGuards();
    $this->actingAs($suspendedOwner, 'merchant')
        ->deleteJson("/api/merchant/credentials/{$existing->credential->id}")
        ->assertOk();

    expect($existing->credential->refresh()->revoked_at)->not->toBeNull();
});

it('requires a merchant session for every credential route', function () {
    $issued = app(CredentialService::class)
        ->issueForMerchantUser($this->merchant, 'TillPoint POS', ['rates:read'], $this->owner);

    $this->getJson('/api/merchant/credentials')->assertUnauthorized();
    $this->postJson('/api/merchant/credentials', [
        'label' => 'Anonymous POS',
        'abilities' => ['rates:read'],
    ])->assertUnauthorized();
    $this->deleteJson("/api/merchant/credentials/{$issued->credential->id}")->assertUnauthorized();

    expect($issued->credential->refresh()->revoked_at)->toBeNull();
});
