<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Standing\ValidationSweeper;
use App\Jobs\SendCustomerSms;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\NotificationTemplate;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

afterEach(function () {
    Carbon::setTestNow();
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

it('writes money in English, always', function () {
    // Every notification body is English by decision (2026-08-17), so money
    // is always "MVR" before the figure.
    expect(NotificationService::money(123456))->toBe('MVR 1,234.56');
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

it('sends the English body even when a Dhivehi one is on file', function () {
    // The 2026-08-17 decision: every notification goes out in English. The
    // body_dv column survives, but nothing reads it any more.
    Queue::fake();

    NotificationTemplate::query()->where('key', 'payout_paid')->update([
        'active' => true,
        'body_en' => 'Paid {{amount}}. Ref {{reference}}.',
        'body_dv' => 'މަންފާ އިން {{amount}} ލިބިއްޖެ.',
    ]);

    app(NotificationService::class)->send(
        NotificationTemplateKey::PayoutPaid,
        Customer::factory()->create(['phone' => '+9607712345']),
        ['amount' => 'MVR 40.00', 'reference' => 'FT1'],
    );

    Queue::assertPushed(SendCustomerSms::class, function (SendCustomerSms $job): bool {
        $body = (fn (): string => $this->body)->call($job);

        return $body === 'Paid MVR 40.00. Ref FT1.';
    });
});

it('tells the customer when their cashback is validated onto the clock', function () {
    // The owner's 2026-08-17 ask: a message at the moment Pending becomes
    // Confirmed. The sweeper is that moment for every windowed sale.
    Queue::fake();

    $occurredAt = CarbonImmutable::parse('2026-08-01T10:00:00+00:00');
    Carbon::setTestNow($occurredAt);

    $merchant = Merchant::factory()->create(['validation_window_days' => 1]);
    $customer = Customer::factory()->create(['phone' => '+9607712345']);

    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => 'awaiting_validation',
        'occurred_at' => $occurredAt,
        'cashback_laari' => 750,
    ]);

    Carbon::setTestNow($occurredAt->addDay());
    app(ValidationSweeper::class)->run();

    Queue::assertPushed(SendCustomerSms::class, function (SendCustomerSms $job) use ($merchant): bool {
        $body = (fn (): string => $this->body)->call($job);

        return str_contains($body, 'MVR 7.50') && str_contains($body, (string) $merchant->name);
    });
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
        // English is the only body: no language indicator and no body_dv
        // travel on the wire any more.
        ->assertJsonMissingPath('data.sends')
        ->assertJsonMissingPath('data.body_dv')
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

it('survives a poisoned template cache instead of eating the notification', function () {
    // The 2026-08-17 incident: a serialized MODEL cached by one build came
    // back as __PHP_Incomplete_Class under another, template() hit its own
    // return type, and the payout-paid push died before dispatch. The cache
    // now stores attributes; anything else in the slot is a miss.
    $service = app(NotificationService::class);
    $key = NotificationTemplateKey::PayoutPaid;

    // Garbage of the exact hostile shape: an object, not an array.
    Cache::put(
        'notification_template:v1:'.$key->value,
        new stdClass,
        300,
    );

    $template = $service->template($key);

    expect($template)->not->toBeNull()
        ->and($template->getAttribute('key'))->toBe(NotificationTemplateKey::PayoutPaid);

    // And the healed cache round-trips as a real model.
    expect($service->template($key)?->body())->toBe($template->body());
});
