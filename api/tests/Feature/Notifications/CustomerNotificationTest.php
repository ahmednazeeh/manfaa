<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Jobs\SendCustomerSms;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * What the platform says to a customer, and — more importantly — when it
 * stays quiet. This sits beside code that moves money, so the tests that
 * matter most are the ones proving it cannot disturb it.
 */
beforeEach(function () {
    $this->admin = AdminUser::factory()->create(['role' => 'superadmin']);
});

it('seeds one template per moment in the catalogue, and no others', function () {
    // A key with no row is a message nobody can edit; a row with no key is
    // words nobody ever reads.
    expect(NotificationTemplate::query()->pluck('key')->map->value->sort()->values()->all())
        ->toBe(collect(NotificationTemplateKey::values())->sort()->values()->all());
});

it('leaves the per-sale message switched off until someone decides otherwise', function () {
    // It fires on every sale. Switching on a per-sale SMS bill is a decision
    // with a number attached, not one inherited from a migration.
    expect(NotificationTemplate::query()->where('key', 'cashback_earned')->sole()->active)
        ->toBeFalse();
});

it('sends nothing at all while a template is inactive', function () {
    Queue::fake();

    app(NotificationService::class)->send(
        NotificationTemplateKey::CashbackEarned,
        Customer::factory()->create(['phone' => '+9607712345']),
        ['amount' => 'MVR 9.60', 'store' => 'Island Mart'],
    );

    Queue::assertNothingPushed();
});

it('sends nothing to a customer with no phone on file', function () {
    Queue::fake();
    NotificationTemplate::query()->where('key', 'payout_paid')->update(['active' => true]);

    app(NotificationService::class)->send(
        NotificationTemplateKey::PayoutPaid,
        Customer::factory()->create(['phone' => '']),
        ['amount' => 'MVR 1.00', 'reference' => 'X'],
    );

    Queue::assertNothingPushed();
});

it('renders the tokens it knows and leaves a mistyped one visible', function () {
    // An admin who writes {{ammount}} should SEE it, rather than find a
    // sentence with a hole where a number belongs.
    expect(NotificationService::render(
        'You earned {{amount}} at {{store}}, not {{ammount}}.',
        ['amount' => 'MVR 9.60', 'store' => 'Island Mart'],
    ))->toBe('You earned MVR 9.60 at Island Mart, not {{ammount}}.');
});

it('writes money the way the template\'s own language writes it', function () {
    // Never "MVR" inside a Thaana sentence, and the word follows the figure.
    expect(NotificationService::money(123456, dhivehi: true))->toBe('1,234.56 ރުފިޔާ')
        ->and(NotificationService::money(123456, dhivehi: false))->toBe('MVR 1,234.56');
});

it('queues exactly one message for an active template', function () {
    Queue::fake();
    NotificationTemplate::query()->where('key', 'payout_paid')->update(['active' => true]);

    app(NotificationService::class)->send(
        NotificationTemplateKey::PayoutPaid,
        Customer::factory()->create(['phone' => '+9607712345']),
        ['amount' => 'MVR 40.00', 'reference' => 'FT2026081600123'],
    );

    Queue::assertPushed(SendCustomerSms::class, 1);
});

it('lets a superadmin rewrite the words and switch a template on', function () {
    $template = NotificationTemplate::query()->where('key', 'cashback_earned')->sole();

    $this->actingAs($this->admin, 'admin')
        ->patchJson("/api/admin/notification-templates/{$template->id}", [
            'body_en' => 'Nice one — {{amount}} back from {{store}}.',
            'active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.active', true)
        // The screen is told which body actually goes out, rather than
        // leaving an admin to guess which one they just edited.
        ->assertJsonPath('data.sends', 'dv')
        ->assertJsonPath('data.variables.0.token', 'amount');

    expect($template->refresh()->body_en)->toBe('Nice one — {{amount}} back from {{store}}.')
        ->and($template->updated_by)->toBe($this->admin->id);
});

it('keeps template writes to superadmins', function () {
    $id = NotificationTemplate::query()->first()->id;

    $this->actingAs(AdminUser::factory()->create(['role' => 'admin']), 'admin')
        ->patchJson("/api/admin/notification-templates/{$id}", ['active' => true])
        ->assertForbidden();
});

it('refuses a body long enough to become several billed messages', function () {
    $id = NotificationTemplate::query()->first()->id;

    $this->actingAs($this->admin, 'admin')
        ->patchJson("/api/admin/notification-templates/{$id}", ['body_en' => str_repeat('a', 481)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body_en');
});
