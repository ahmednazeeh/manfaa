<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Tax\FeeTreatment;
use App\Jobs\SendCustomerSms;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\TaxSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * THE SWITCH ITSELF: GET /api/admin/platform/tax-settings and its
 * superadmin-only PATCH.
 *
 * Three rules, all of them the point:
 *
 *  - GST cannot be enabled without the three facts a tax invoice must carry
 *    (TIN, business name, activity number). Enabling without them would mint
 *    non-compliant records at till speed, so the endpoint answers 422;
 *  - `enabled_at` is stamped on the TRANSITION, never on every save;
 *  - the merchant notice fires ONCE, on that transition, and never on a
 *    later rate edit.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

    $this->admin = AdminUser::factory()->create(['role' => 'admin']);
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** An approved store with a verified number and a settlements-watching owner. */
function gstMerchant(string $name): Merchant
{
    $merchant = Merchant::factory()->create([
        'name' => $name,
        'status' => 'active',
        'contact_phone' => '+9607'.fake()->unique()->numberBetween(100000, 999999),
    ]);

    MerchantRate::factory()->for($merchant)->create();
    MerchantUser::factory()->for($merchant)->owner()->create();

    return $merchant;
}

it('ships disabled, at 8%, on top', function () {
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/platform/tax-settings')
        ->assertOk()
        ->assertJsonPath('data.gst_enabled', false)
        // PLAN §1 wire format: a percent string, never basis points.
        ->assertJsonPath('data.gst_rate_percent', '8.00')
        ->assertJsonPath('data.fee_treatment', 'on_top')
        ->assertJsonPath('data.enabled_at', null)
        ->assertJsonPath('data.can_enable', false)
        ->assertJsonPath('data.missing_identity_fields', [
            'gst_tin', 'gst_business_name', 'gst_activity_number',
        ]);
});

it('refuses to enable without the details a tax invoice must carry', function () {
    Queue::fake();
    gstMerchant('Reef Mart');

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['gst_enabled' => true])
        ->assertStatus(422);

    expect(TaxSetting::current()->gst_enabled)->toBeFalse()
        ->and(TaxSetting::current()->enabled_at)->toBeNull();

    // Two of the three is still not a tax invoice.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', [
            'gst_enabled' => true,
            'gst_tin' => '1234567GST501',
            'gst_business_name' => 'Manfaa Pvt Ltd',
        ])
        ->assertStatus(422)
        // Prose, in the words above the inputs — never `gst_activity_number`,
        // which is the API telling an operator about its own JSON.
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'tax activity number')
            && ! str_contains($message, 'gst_activity_number'));

    expect(TaxSetting::current()->gst_enabled)->toBeFalse();

    // Nothing was announced, because nothing was enabled.
    Queue::assertNotPushed(SendCustomerSms::class);
});

it('enables with the identity supplied in the same request, and stamps the instant', function () {
    Queue::fake();

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', [
            'gst_enabled' => true,
            'gst_tin' => '1234567GST501',
            'gst_business_name' => 'Manfaa Pvt Ltd',
            'gst_activity_number' => 'A-0091',
            'fee_treatment' => 'inclusive',
            'gst_rate_percent' => '8',
        ])
        ->assertOk()
        ->assertJsonPath('data.gst_enabled', true)
        ->assertJsonPath('data.gst_rate_percent', '8.00')
        ->assertJsonPath('data.fee_treatment', 'inclusive')
        ->assertJsonPath('data.can_enable', true)
        ->assertJsonPath('data.missing_identity_fields', []);

    $settings = TaxSetting::current();

    expect($settings->gst_enabled)->toBeTrue()
        ->and($settings->gst_rate_bp)->toBe(800)
        ->and($settings->fee_treatment)->toBe(FeeTreatment::Inclusive)
        ->and($settings->enabled_at)->not->toBeNull()
        ->and($settings->updated_by)->toBe($this->superadmin->id);
});

it('tells every approved store ONCE when GST is switched on, and never on a rate edit', function () {
    Queue::fake();

    gstMerchant('Reef Mart');
    gstMerchant('Sea House Cafe');
    // Still in review: no settlements, no fee, nothing yet to be taxed on.
    $pending = Merchant::factory()->create(['status' => 'pending_review', 'contact_phone' => '+9607771234']);
    MerchantUser::factory()->for($pending)->owner()->create();

    // SUSPENDED — the platform's own temporary state for an overdue
    // settlement. It owes money whose shape is about to change, and this
    // announcement fires exactly once, so skipping it would mean never
    // telling it at all.
    $suspended = gstMerchant('Coral Grocers');
    $suspended->update(['status' => 'suspended']);

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', [
            'gst_enabled' => true,
            'gst_tin' => '1234567GST501',
            'gst_business_name' => 'Manfaa Pvt Ltd',
            'gst_activity_number' => 'A-0091',
        ])
        ->assertOk();

    // One text per APPROVED store — trading or suspended — to the store's
    // own verified number.
    Queue::assertPushed(SendCustomerSms::class, 3);

    // A rate edit is a correction to a number the merchant reads on every
    // settlement screen — not news, and not worth a second interruption.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['gst_rate_percent' => '10'])
        ->assertOk()
        ->assertJsonPath('data.gst_rate_percent', '10.00');

    Queue::assertPushed(SendCustomerSms::class, 3);

    // Nor does a treatment switch, nor re-saving an already-enabled row.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['fee_treatment' => 'inclusive'])
        ->assertOk();

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['gst_enabled' => true])
        ->assertOk();

    Queue::assertPushed(SendCustomerSms::class, 3);
});

it('leaves enabled_at where it was through a rate edit', function () {
    Queue::fake();

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', [
            'gst_enabled' => true,
            'gst_tin' => '1234567GST501',
            'gst_business_name' => 'Manfaa Pvt Ltd',
            'gst_activity_number' => 'A-0091',
        ])
        ->assertOk();

    $stampedAt = TaxSetting::current()->enabled_at;

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-01T12:00:00+05:00'));

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['gst_rate_percent' => '12.50'])
        ->assertOk();

    expect(TaxSetting::current()->enabled_at->toIso8601String())->toBe($stampedAt->toIso8601String())
        ->and(TaxSetting::current()->gst_rate_bp)->toBe(1250);
});

it('is readable by any admin and writable only by a superadmin', function () {
    // The identity first, written by the role that owns it.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', [
            'gst_tin' => '1234567GST501',
            'gst_business_name' => 'Manfaa Pvt Ltd',
            'gst_activity_number' => 'A-0091',
        ])
        ->assertOk()
        ->assertJsonPath('data.gst_tin', '1234567GST501');

    // A plain admin reads the POLICY — the only part their screens use —
    // and not the platform's own tax registration.
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/platform/tax-settings')
        ->assertOk()
        ->assertJsonPath('data.gst_enabled', false)
        ->assertJsonPath('data.gst_rate_percent', '8.00')
        ->assertJsonPath('data.fee_treatment', 'on_top')
        ->assertJsonPath('data.can_enable', true)
        ->assertJsonPath('data.gst_tin', null)
        ->assertJsonPath('data.gst_business_name', null)
        ->assertJsonPath('data.gst_activity_number', null);

    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/platform/tax-settings')
        ->assertOk()
        ->assertJsonPath('data.gst_tin', '1234567GST501')
        ->assertJsonPath('data.gst_business_name', 'Manfaa Pvt Ltd')
        ->assertJsonPath('data.gst_activity_number', 'A-0091');

    $this->actingAs($this->admin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['gst_rate_percent' => '10'])
        ->assertForbidden();

    expect(TaxSetting::current()->gst_rate_bp)->toBe(800);
});

it('refuses a rate the platform could never price', function () {
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['gst_rate_percent' => '25'])
        ->assertStatus(422);

    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['fee_treatment' => 'sideways'])
        ->assertStatus(422);

    // Over-precise percents are refused rather than silently rounded.
    $this->actingAs($this->superadmin, 'admin')
        ->patchJson('/api/admin/platform/tax-settings', ['gst_rate_percent' => '8.005'])
        ->assertStatus(422);
});

it('carries the moment in the notification catalogue as a merchant-staff key', function () {
    $key = NotificationTemplateKey::GstNowApplies;

    expect($key->value)->toBe('gst_now_applies')
        ->and($key->isForMerchantStaff())->toBeTrue()
        // The catalogue guard: every merchant moment reaches the store by
        // SMS as well as push.
        ->and($key->smsToMerchantContact())->toBeTrue()
        ->and($key->isMarketplace())->toBeFalse()
        ->and($key->label())->toBe('GST now applies')
        ->and(array_keys($key->variables()))->toBe(['rate', 'date', 'effect'])
        ->and($key->pushTitle())->toHaveKeys(['en', 'dv'])
        // Seeded ACTIVE by its migration, or nothing would ever send.
        ->and(DB::table('notification_templates')->where('key', 'gst_now_applies')->value('active'))->toBeTrue();
});
