<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Platform\PlatformConfig;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\NotificationTemplate;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * MR4 — the deadline reminders (manfaa:remind-settlements) and the §7
 * ladder's new push half. Everything time-travels in the business timezone,
 * every amount asserted is a SERVER-computed figure, and the tests that
 * matter most are the silent ones.
 */

/**
 * One store, one payable sale whose clock started 09:00 business time on
 * 1 Aug — the same instant EscalationTest measures from — owing 2000
 * cashback + 750 fee = MVR 27.50, with a registered owner device.
 *
 * @return array{0: Merchant, 1: MerchantUser, 2: CarbonImmutable}
 */
function reminderFixture(): array
{
    $clockStart = CarbonImmutable::parse('2026-08-01T09:00:00+05:00')->utc();

    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();

    Transaction::factory()->for($merchant)->create([
        'state' => 'payable_unfunded',
        'clock_start_at' => $clockStart,
        'due_at' => $clockStart->addDays(15),
        'cashback_laari' => 2_000,
        'fee_laari' => 750,
        'fee_gst_laari' => 0,
    ]);

    return [$merchant, $owner, $clockStart];
}

function reminderDevice(MerchantUser $user, string $locale = 'en'): void
{
    $auth = app(MobileTokenService::class)->issue($user, MobileAudience::Merchant, 'Till')->plainTextToken;

    DeviceToken::query()->create([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($auth)->getKey(),
        'token' => 'till-'.Str::random(8),
        'platform' => 'android',
        'locale' => $locale,
    ]);
}

/**
 * The queued pushes as [title, body, templateKey] triples. The job's fields
 * are private; reflection reads them the way MobilePushTest already does.
 *
 * @return Collection<int, array{title: string, body: string, key: string}>
 */
function reminderPushes(): Collection
{
    $read = function (SendPushNotification $job, string $prop): string {
        return (string) (new ReflectionProperty(SendPushNotification::class, $prop))->getValue($job);
    };

    return collect(Queue::pushedJobs()[SendPushNotification::class] ?? [])
        ->map(fn (array $entry): array => [
            'title' => $read($entry['job'], 'title'),
            'body' => $read($entry['job'], 'body'),
            'key' => $read($entry['job'], 'templateKey'),
        ])->values();
}

// ------------------------------------------------- prompt_discount_expiring

it('fires on day 9 with the settle-all preview\'s own saving in the body', function () {
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    // Day 9 (max_age 10 − 1): the last morning the discount is earnable.
    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    // The saving is the preview's discount_laari — ceil(750 × 5%) = 38
    // laari — never re-derived here or in the template.
    $push = reminderPushes()->sole();
    expect($push['key'])->toBe('prompt_discount_expiring')
        ->and($push['title'])->toBe('Discount expiring')
        ->and($push['body'])->toBe('Settle everything outstanding today and keep your 5% prompt-payment discount — you save MVR 0.38.');

    // And the evidenced notice row, ladder-style.
    $notice = MerchantNotice::query()->where('type', 'prompt_discount_expiring')->sole();
    expect($notice->channel)->toBe('push')
        ->and($notice->payload['discount_laari'])->toBe(38)
        ->and($notice->payload['age_days'])->toBe(9);
});

it('stays silent when nothing is outstanding', function () {
    Queue::fake();
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    reminderDevice($owner);

    // A tracked sale is not payable; no clock is running on anything.
    Transaction::factory()->for($merchant)->create(['state' => 'tracked']);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T09:00:00+05:00'));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(MerchantNotice::query()->count())->toBe(0);
});

it('stays silent on day 9 when the preview says no discount is earnable', function () {
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    // The incentive is switched off, so the settle-all preview grants 0 —
    // a reminder would promise money the submit path will not hand over.
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(MerchantNotice::query()->count())->toBe(0);
});

// ------------------------------------------- days 13 and 14 belong to the ladder

it('leaves days 13 and 14 to the escalation ladder', function () {
    // `settlement_due_soon` fired on exactly the two mornings
    // EscalationLadder already sends `urgent_day13` on, from a second
    // scheduler running at the same hour: two pushes AND two texts a day
    // saying much the same thing (owner, 2026-08-20). The sweep now keeps
    // only the discount deadline, which the ladder has no equivalent for.
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    foreach ([13, 14] as $day) {
        Carbon::setTestNow($clockStart->addDays($day));
        $this->artisan('manfaa:remind-settlements')->assertExitCode(0);
    }

    expect(reminderPushes())->toHaveCount(0);

    // The ladder still covers those mornings, so the merchant is not left
    // in silence — the message simply arrives once.
    Carbon::setTestNow($clockStart->addDays(13));
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    expect(reminderPushes()->sole()['key'])->toBe('urgent_day13');
});

it('sends nothing new when re-run the same day', function () {
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    // Day 9 — the discount deadline, the sweep's only remaining moment.
    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    // One push, one notice — the second run found the morning's evidence row.
    expect(reminderPushes())->toHaveCount(1)
        ->and(MerchantNotice::query()->count())->toBe(1);
});

it('computes its day thresholds from the live platform settings', function () {
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    $config = app(PlatformConfig::class);
    $config->set('prompt_discount_max_age_days', 7);
    $config->set('settlement_due_days', 20);

    // The DEFAULT day 9 is now past the shortened discount window — silence.
    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);
    expect(reminderPushes())->toHaveCount(0);

    // Day 6 == max_age(7) − 1 is the new last-earnable morning.
    Carbon::setTestNow($clockStart->addDays(6));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);
    expect(reminderPushes()->sole()['key'])->toBe('prompt_discount_expiring');

    // And nothing at the old due-soon mornings: those are the ladder's.
    Carbon::setTestNow($clockStart->addDays(18));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    expect(reminderPushes()->where('key', 'settlement_due_soon'))->toHaveCount(0);
});

// ----------------------------------------------------------- ladder to push

it('pushes each escalation rung exactly once, deduped by the ladder itself', function () {
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    // Each rung: run TWICE — the merchant_notices dedupe that already
    // guards the row now provably guards the push behind it.
    Carbon::setTestNow($clockStart->addDays(10));
    $this->artisan('manfaa:escalate')->assertExitCode(0);
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    expect(reminderPushes()->where('key', 'reminder_day10'))->toHaveCount(1);

    Carbon::setTestNow($clockStart->addDays(13));
    $this->artisan('manfaa:escalate')->assertExitCode(0);
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    $urgent = reminderPushes()->where('key', 'urgent_day13')->sole();
    expect($urgent['body'])->toBe('Urgent: MVR 27.50 is still outstanding and falls due on 16 Aug 2026. Settle now to keep cashback running.');

    Carbon::setTestNow($clockStart->addDays(15));
    $this->artisan('manfaa:escalate')->assertExitCode(0);
    $this->artisan('manfaa:escalate')->assertExitCode(0);

    expect(reminderPushes()->where('key', 'due_day15'))->toHaveCount(1)
        ->and(reminderPushes())->toHaveCount(3);
});

// ------------------------------------------------------------ delivery rules

it('keeps the body English for a Dhivehi device, localising only the title', function () {
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner, locale: 'dv');
    app()->setLocale('dv');

    // Day 9 — the discount deadline, the sweep's only remaining moment.
    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    $push = reminderPushes()->sole();
    // Title localised, body English: the money and the dates are the same
    // figures the settlement screen shows.
    expect($push['title'])->toBe(NotificationTemplateKey::PromptDiscountExpiring->pushTitle()['dv'])
        ->and($push['body'])->toContain('MVR');
});

it('reaches only staff holding settlements.view', function () {
    Queue::fake();
    [$merchant, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    $cashier = MerchantUser::factory()->for($merchant)->withRole(
        MerchantRole::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Cashier',
            'slug' => 'cashier-'.$merchant->id,
            'permissions' => [Permission::CreditsCreate->value],
            'is_owner' => false,
            'is_system' => false,
        ])
    )->create();
    reminderDevice($cashier);

    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    // A deadline reaching a till that cannot open Settlements is noise.
    Queue::assertPushed(SendPushNotification::class, 1);
});

it('caches the reminder templates as attributes, never models', function () {
    Queue::fake();
    [, $owner, $clockStart] = reminderFixture();
    reminderDevice($owner);

    Carbon::setTestNow($clockStart->addDays(9));
    $this->artisan('manfaa:remind-settlements')->assertExitCode(0);

    // The 2026-08-17 poisoning incident: a cached MODEL deserialises as
    // __PHP_Incomplete_Class under another build. The slot must hold a
    // plain attribute array.
    $cached = Cache::get('notification_template:v1:'.NotificationTemplateKey::PromptDiscountExpiring->value);

    expect($cached)->toBeArray()
        ->and($cached['key'])->toBe('prompt_discount_expiring')
        ->and($cached['body_en'])->toContain('{{amount}}');
});

it('renders the reminder through NotificationService with English-only variables', function () {
    // The template's tokens and the command's variables must agree — a
    // mistyped token would ship as literal braces in a push.
    $body = NotificationTemplate::query()
        ->where('key', NotificationTemplateKey::PromptDiscountExpiring->value)
        ->sole()
        ->body();

    $rendered = NotificationService::render($body, [
        'amount' => 'MVR 0.38',
        'rate' => '5%',
    ]);

    expect($rendered)->not->toContain('{{')
        ->and($rendered)->toContain('MVR 0.38');
});
