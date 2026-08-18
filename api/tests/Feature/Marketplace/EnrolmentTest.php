<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Platform\PlatformConfig;
use App\Jobs\SendCustomerSms;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MP1 — becoming a vendor (PLAN-marketplace.md §9, §10).
 *
 * Marketplace is optional for a merchant and off for the platform until a
 * superadmin says otherwise, and "off" has to mean the server refuses —
 * not that four clients agree to hide a button.
 */
function enableMarketplace(): void
{
    app(PlatformConfig::class)->set('marketplace_enabled', 1);
}

beforeEach(function () {
    $this->merchant = Merchant::factory()->create([
        'status' => 'active',
        'name' => 'Island Mart',
        'contact_phone' => '+9607771234',
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

// ------------------------------------------------------------ kill switch

it('does not appear to exist while the switch is off', function () {
    // 404, not 403. A 403 confirms the route is there and something is
    // being withheld; a product we have not launched should simply not
    // look like a product we have.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/marketplace/enrolment')
        ->assertNotFound()
        ->assertJsonPath('code', 'marketplace_disabled');

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/marketplace/kyb')
        ->assertNotFound();
});

it('tells both apps whether the marketplace exists', function () {
    $this->getJson('/api/mobile/v1/config')
        ->assertOk()
        ->assertJsonPath('data.features.marketplace', false);

    enableMarketplace();

    $this->getJson('/api/mobile/v1/config')
        ->assertOk()
        ->assertJsonPath('data.features.marketplace', true);
});

// -------------------------------------------------------------- enrolment

it('starts with no marketplace state at all', function () {
    enableMarketplace();

    // The absence of a row IS a state, and the client should not have to
    // know that. Most merchants only ever want cashback.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/marketplace/enrolment')
        ->assertOk()
        ->assertJsonPath('data.state', 'not_enrolled')
        ->assertJsonPath('data.missing_documents', [
            'business_registration', 'owner_id', 'bank_letter',
        ])
        // The rate it would be charged: the platform default, in percent.
        ->assertJsonPath('data.order_fee_percent', '2.00');
});

it('opts in and records the profile sheet', function () {
    enableMarketplace();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/enrolment', [
            'business_type' => 'pvt_ltd',
            'fulfilment' => 'both',
            'prep_time_min' => 30,
            'prep_time_max' => 60,
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'pending_kyb');

    expect($this->merchant->fresh()->marketplace->business_type)->toBe('pvt_ltd');
});

it('refuses a prep window that runs backwards', function () {
    enableMarketplace();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/enrolment', [
            'business_type' => 'sole_prop',
            'fulfilment' => 'pickup',
            'prep_time_min' => 60,
            'prep_time_max' => 30,
        ])
        ->assertUnprocessable();
});

it('keeps the switch away from staff without the authority', function () {
    enableMarketplace();

    $cashier = MerchantUser::factory()->for($this->merchant)->create();

    $this->actingAs($cashier, 'merchant')
        ->postJson('/api/merchant/marketplace/enrolment', [
            'business_type' => 'pvt_ltd',
            'fulfilment' => 'both',
        ])
        ->assertForbidden();
});

// -------------------------------------------------------------------- KYB

it('will not submit an application that is missing papers', function () {
    enableMarketplace();
    Storage::fake('local');

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/enrolment', [
            'business_type' => 'pvt_ltd', 'fulfilment' => 'both',
        ])->assertOk();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/documents', [
            'kind' => 'business_registration',
            'file' => UploadedFile::fake()->image('reg.jpg'),
        ])->assertCreated();

    // A queue full of half-finished applications wastes the one scarce
    // thing here, which is the reviewer's attention.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/submit')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'kyb_incomplete')
        ->assertJsonPath('meta.missing', ['owner_id', 'bank_letter']);
});

it('replaces a paper rather than accumulating copies of it', function () {
    enableMarketplace();
    Storage::fake('local');

    $upload = fn (string $name) => $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/documents', [
            'kind' => 'owner_id',
            'file' => UploadedFile::fake()->image($name),
        ])->assertCreated();

    $upload('blurry.jpg');
    $first = $this->merchant->kybDocuments()->where('kind', 'owner_id')->sole();

    $upload('clear.jpg');

    expect($this->merchant->kybDocuments()->where('kind', 'owner_id')->count())->toBe(1)
        ->and($this->merchant->fresh()->kybDocuments()->sole()->original_name)->toBe('clear.jpg');

    // The superseded file is GONE from disk. An identity document nobody
    // can reach is still a document we are holding.
    Storage::disk('local')->assertMissing($first->path);
});

it('never publishes the stored path of an identity document', function () {
    enableMarketplace();
    Storage::fake('local');

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/documents', [
            'kind' => 'bank_letter',
            'file' => UploadedFile::fake()->create('letter.pdf', 40, 'application/pdf'),
        ])->assertCreated();

    $body = $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/marketplace/enrolment')
        ->assertOk()
        ->json();

    expect(json_encode($body))->not->toContain('kyb/');
});

it('refuses to hand a document to a merchant who does not own it', function () {
    enableMarketplace();
    Storage::fake('local');

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/documents', [
            'kind' => 'owner_id',
            'file' => UploadedFile::fake()->image('id.jpg'),
        ])->assertCreated();

    $document = $this->merchant->kybDocuments()->sole();

    $intruder = MerchantUser::factory()
        ->for(Merchant::factory()->create(['status' => 'active']))
        ->owner()
        ->create();

    $this->actingAs($intruder, 'merchant')
        ->get("/api/merchant/marketplace/documents/{$document->id}")
        ->assertNotFound();
});

// ----------------------------------------------------------------- review

function completeApplication($test): void
{
    enableMarketplace();
    Storage::fake('local');

    $test->actingAs($test->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/enrolment', [
            'business_type' => 'pvt_ltd', 'fulfilment' => 'both',
        ])->assertOk();

    foreach (['business_registration', 'owner_id', 'bank_letter'] as $kind) {
        $test->actingAs($test->owner, 'merchant')
            ->postJson('/api/merchant/marketplace/documents', [
                'kind' => $kind,
                'file' => UploadedFile::fake()->image("{$kind}.jpg"),
            ])->assertCreated();
    }

    $test->actingAs($test->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/submit')->assertOk();
}

it('approves a complete application and tells the store', function () {
    Queue::fake();
    completeApplication($this);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/kyb/{$this->merchant->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'active');

    $merchant = $this->merchant->fresh();

    expect($merchant->marketplace->isLive())->toBeTrue()
        ->and($merchant->sellsOnMarketplace())->toBeTrue()
        // Every pending paper is accepted along with the application.
        ->and($merchant->kybDocuments()->where('state', 'accepted')->count())->toBe(3);

    // Merchant moments text the store (2026-08-18).
    Queue::assertPushed(SendCustomerSms::class);
});

it('will not approve an application that is missing papers', function () {
    enableMarketplace();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/enrolment', [
            'business_type' => 'sole_prop', 'fulfilment' => 'pickup',
        ])->assertOk();

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    // Approving here would put a store live owing us a document nobody
    // would ever chase.
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/kyb/{$this->merchant->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'kyb_incomplete');
});

it('rejects with a reason the merchant can act on, and keeps their papers', function () {
    Queue::fake();
    completeApplication($this);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/kyb/{$this->merchant->id}/reject", [
            'reason' => 'The bank letter is addressed to a different business name.',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected');

    $merchant = $this->merchant->fresh();

    expect($merchant->marketplace->rejected_reason)
        ->toContain('different business name')
        ->and($merchant->sellsOnMarketplace())->toBeFalse()
        // A store fixing ONE paper must not re-upload the other two.
        ->and($merchant->kybDocuments()->count())->toBe(3);
});

it('refuses a rejection with no reason', function () {
    completeApplication($this);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/kyb/{$this->merchant->id}/reject", [])
        ->assertUnprocessable();
});

it('keeps the decision away from an ordinary admin', function () {
    completeApplication($this);

    $admin = AdminUser::factory()->create(['role' => 'admin']);

    // Reading the queue is ordinary admin work...
    $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/marketplace/kyb')
        ->assertOk();

    // ...deciding is not.
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/kyb/{$this->merchant->id}/approve")
        ->assertForbidden();
});

it('lets a rejected store fix its papers and come back', function () {
    Queue::fake();
    completeApplication($this);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/kyb/{$this->merchant->id}/reject", [
            'reason' => 'Bank letter unreadable.',
        ])->assertOk();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/documents', [
            'kind' => 'bank_letter',
            'file' => UploadedFile::fake()->image('better.jpg'),
        ])->assertCreated();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/marketplace/submit')
        ->assertOk()
        ->assertJsonPath('data.state', 'pending_kyb');

    expect($this->merchant->fresh()->marketplace->rejected_reason)->toBeNull();
});

it('does not confuse being a vendor with being open', function () {
    Queue::fake();
    completeApplication($this);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/marketplace/kyb/{$this->merchant->id}/approve")->assertOk();

    // A vendor who pauses their store is not selling, whatever their
    // marketplace state says. Neither condition implies the other.
    $this->merchant->fresh()->forceFill(['unpublished_at' => now()])->save();

    expect($this->merchant->fresh()->sellsOnMarketplace())->toBeFalse();
});

it('seeds a template and a push title for both enrolment outcomes', function () {
    foreach ([NotificationTemplateKey::MarketplaceApproved, NotificationTemplateKey::MarketplaceRejected] as $key) {
        expect(NotificationTemplate::query()->where('key', $key->value)->exists())
            ->toBeTrue("no seeded row for {$key->value}");
        expect(trim($key->pushTitle()['dv']))->not->toBe('');
    }
});
