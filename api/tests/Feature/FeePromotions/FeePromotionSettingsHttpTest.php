<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\FeePromotion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * THE SWITCHES THEMSELVES: GET /api/admin/platform/fee-promotions and its
 * superadmin-only PATCH.
 *
 * The refusals are the feature. A promotion is a promise printed on a
 * merchant's screen, and every incoherent state below is one this endpoint
 * must never store:
 *
 *   - switched on with no fee set (0.00% is a CHOICE, not a default);
 *   - an introductory window of zero days, which nobody is ever inside;
 *   - a platform-wide offer missing a start or an end;
 *   - an end that precedes its start;
 *   - a fee ABOVE the cheapest tier it could replace — a "promotion" that
 *     costs the merchant more;
 *   - switched on with no banner wording in one of the two languages.
 */
beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-25T12:00:00+05:00'));

    $this->admin = AdminUser::factory()->create(['role' => 'admin']);
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A complete, coherent introductory offer — the happy payload. */
function introPayload(array $overrides = []): array
{
    return array_merge([
        'intro_enabled' => true,
        'intro_days' => 30,
        'intro_fee_percent' => '0.00',
        'intro_banner_en' => 'No platform fee for your first 30 days.',
        'intro_banner_dv' => 'ފުރަތަމަ 30 ދުވަހު ޕްލެޓްފޯމް ފީއެއް ނުނަގާނެ.',
    ], $overrides);
}

/** The same for the platform-wide offer. */
function widePayload(array $overrides = []): array
{
    return array_merge([
        'wide_enabled' => true,
        'wide_from' => '2026-09-01T00:00:00Z',
        'wide_to' => '2026-09-15T00:00:00Z',
        'wide_fee_percent' => '0.00',
        'wide_banner_en' => 'Launch offer: no platform fee this fortnight.',
        'wide_banner_dv' => 'ލޯންޗް އޮފަރ: މި ދެ ހަފްތާ ޕްލެޓްފޯމް ފީއެއް ނެތް.',
    ], $overrides);
}

it('ships with both switches off and nothing set', function (): void {
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/platform/fee-promotions')
        ->assertOk()
        ->assertJsonPath('data.intro.enabled', false)
        ->assertJsonPath('data.intro.days', 0)
        ->assertJsonPath('data.intro.platform_fee_percent', null)
        ->assertJsonPath('data.platform_wide.enabled', false)
        ->assertJsonPath('data.platform_wide.from', null)
        // The bound both fees are judged against: the cheapest band of the
        // seeded §4 schedule, as a percent string.
        ->assertJsonPath('data.max_promotional_fee_percent', '0.25')
        // What a promotion does NOT cover, said out loud.
        ->assertJsonPath('data.applies_to', ['cashback_platform_fee'])
        ->assertJsonPath('data.excludes', ['marketplace_order_fee'])
        ->assertJsonPath('data.intro.blockers', ['fee_not_set', 'days', 'banner_en_missing', 'banner_dv_missing']);
});

it('lets any admin read it and only a superadmin write it', function (): void {
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/platform/fee-promotions')
        ->assertOk();

    $this->actingAs($this->admin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload())
        ->assertForbidden();

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload())
        ->assertOk()
        ->assertJsonPath('data.intro.enabled', true)
        ->assertJsonPath('data.intro.days', 30)
        ->assertJsonPath('data.intro.platform_fee_percent', '0.00')
        ->assertJsonPath('data.intro.blockers', []);

    expect(FeePromotion::current()->intro_fee_bp)->toBe(0)
        ->and(FeePromotion::current()->updated_by)->toBe($this->superadmin->id);
});

it('stops an unauthenticated caller at both doors', function (): void {
    $this->getJson('/api/admin/platform/fee-promotions')->assertUnauthorized();
    $this->patchJson('/api/admin/platform/fee-promotions', introPayload())->assertUnauthorized();
});

it('refuses to switch on an offer with no promotional fee set', function (): void {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload(['intro_fee_percent' => null]))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'without a promotional platform fee'));

    expect(FeePromotion::current()->intro_enabled)->toBeFalse();
});

it('refuses an introductory offer of zero days', function (): void {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload(['intro_days' => 0]))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'zero days'));
});

it('refuses a platform-wide offer with no end', function (): void {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', widePayload(['wide_to' => null]))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'a start AND an end'));
});

it('refuses a window whose end precedes its start', function (): void {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', widePayload([
            'wide_from' => '2026-09-15T00:00:00Z',
            'wide_to' => '2026-09-01T00:00:00Z',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'ends before'));

    expect(FeePromotion::current()->wide_enabled)->toBeFalse();
});

it('refuses an inverted window even while the offer is still switched OFF', function (): void {
    // A superadmin DRAFTS a campaign — dates first, copy and switch later —
    // and gets the two dates the wrong way round. The table's own
    // `fee_promotions_window_order_check` is unconditional, so this save has
    // to be refused HERE: skipping the check because the switch is off does
    // not make the row storable, it only turns the sentence below into a raw
    // SQLSTATE dump inside a 500, on a screen whose Save button is gated on
    // date FORMAT alone.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', [
            'wide_fee_percent' => '0.00',
            'wide_from' => '2026-10-01T00:00:00+05:00',
            'wide_to' => '2026-09-01T00:00:00+05:00',
            'wide_banner_en' => 'Launch offer.',
            'wide_banner_dv' => 'ލޯންޗް އޮފަރ.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'ends before'));

    expect(FeePromotion::current()->wide_from)->toBeNull();
});

it('still stores a HALF-drawn window while the offer is switched off', function (): void {
    // The other half of the rule above: only the INVERTED window is
    // un-storable. A start with no end yet is a draft the constraint permits,
    // and refusing it would stop a superadmin saving their work in the order
    // they type it. The both-edges refusal belongs to the SWITCH.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', ['wide_from' => '2026-10-01T00:00:00+05:00'])
        ->assertOk();

    expect(FeePromotion::current()->wide_from)->not->toBeNull()
        ->and(FeePromotion::current()->wide_to)->toBeNull();
});

it('refuses a promotional fee ABOVE the cheapest tier it would replace', function (): void {
    // The seeded §4 schedule's cheapest band charges 0.25%. A "promotion" at
    // 1.50% would make the merchant sitting on that band pay six times more.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload(['intro_fee_percent' => '1.50']))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'would pay MORE'));

    // Exactly at the cheapest tier is legal — it charges nobody more, even
    // though it does nothing for the merchants already there.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload(['intro_fee_percent' => '0.25']))
        ->assertOk()
        ->assertJsonPath('data.intro.platform_fee_percent', '0.25');
});

it('refuses to switch on an offer merchants cannot be told about, in either language', function (): void {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload(['intro_banner_dv' => '']))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Dhivehi'));

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload(['intro_banner_en' => null]))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'English'));
});

it('judges the refusals against the row AS IT WOULD BE SAVED, so one request can supply everything', function (): void {
    // The fee, the days, the copy and the switch all arrive together — the
    // TaxSettingsController rule, and the reason a superadmin does not have
    // to save twice.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload())
        ->assertOk();

    // And a request that supplies only the switch, on an empty half of the
    // row, is refused.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', ['wide_enabled' => true])
        ->assertStatus(422);

    // The introductory half it left alone is untouched.
    expect(FeePromotion::current()->intro_enabled)->toBeTrue()
        ->and(FeePromotion::current()->wide_enabled)->toBeFalse();
});

it('stores both kinds on one row and reads them back in the wire grammar', function (): void {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', array_merge(introPayload(['intro_fee_percent' => '0.10']), widePayload()))
        ->assertOk();

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/platform/fee-promotions')
        ->assertOk()
        ->assertJsonPath('data.intro.enabled', true)
        ->assertJsonPath('data.intro.platform_fee_percent', '0.10')
        ->assertJsonPath('data.intro.banner_dv', 'ފުރަތަމަ 30 ދުވަހު ޕްލެޓްފޯމް ފީއެއް ނުނަގާނެ.')
        ->assertJsonPath('data.platform_wide.enabled', true)
        ->assertJsonPath('data.platform_wide.platform_fee_percent', '0.00')
        ->assertJsonPath('data.platform_wide.from', '2026-09-01T00:00:00+00:00')
        ->assertJsonPath('data.platform_wide.to', '2026-09-15T00:00:00+00:00')
        ->assertJsonPath('data.platform_wide.blockers', []);

    // Basis points never appear on the wire, in either direction.
    $body = $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/platform/fee-promotions')
        ->getContent();

    expect($body)->not->toContain('_bp');
});

it('refuses a percent with more than two decimals, as a field error', function (): void {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/fee-promotions', introPayload(['intro_fee_percent' => '0.125']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('intro_fee_percent');
});
