<?php

declare(strict_types=1);

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\SmsSender;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Domain\Onboarding\EmailAlreadyRegisteredException;
use App\Domain\Onboarding\MerchantOtpService;
use App\Models\Merchant;
use App\Models\MerchantOtpCode;
use App\Models\MerchantUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Concurrency proofs for merchant self-signup (§1 decision 2026-08-15) — the
 * store-side mirror of tests/Feature/Customer/OtpRaceTest. A second, real
 * Postgres session plays the competing request; the losing store must never
 * be a 500, a duplicate store, or a widened attempt cap.
 *
 * Rows committed by that session outlive the RefreshDatabase rollback, so
 * everything is keyed to a +96079900xx phone block and a race-mart slug stem
 * that no other test uses, and cleaned up explicitly.
 */
function signupRacePdo(): PDO
{
    $config = config('database.connections.pgsql');

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    // Never let this second session hang the suite on a contended row.
    $pdo->exec("SET lock_timeout = '2s'");

    return $pdo;
}

function signupRaceCleanup(PDO $pdo, bool $releaseTestLocks = false): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // At the END of a test: drop the test transaction and with it every
        // row this connection locked FOR UPDATE, or the deletes below queue
        // behind those locks, time out, and leave litter for the whole run.
        if ($releaseTestLocks && DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }

        $pdo->exec("DELETE FROM merchant_users WHERE email LIKE '%@racemart.mv'");
        $pdo->exec("DELETE FROM merchants WHERE slug LIKE 'race-mart%' OR contact_phone LIKE '+96079900%'");
        $pdo->exec("DELETE FROM merchant_otp_codes WHERE phone LIKE '+96079900%'");
    } catch (PDOException) {
        // A failing test can still hold row locks; leftovers are wiped by
        // the next migrate:fresh and must not mask the real failure.
    }
}

/** Commits a live code row exactly as request() would have written it. */
function commitMerchantOtpRow(PDO $pdo, string $phone, string $code, int $attempts = 0): int
{
    $statement = $pdo->prepare(
        "INSERT INTO merchant_otp_codes (phone, code_hash, expires_at, attempts, created_at, updated_at)
         VALUES (?, ?, now() + interval '10 minutes', ?, now(), now()) RETURNING id"
    );
    $statement->execute([$phone, Hash::make($code), $attempts]);

    return (int) $statement->fetchColumn();
}

/** Commits a verified row carrying a live signup token; returns the token. */
function commitMerchantSignupToken(PDO $pdo, string $phone): string
{
    $token = Str::random(48);

    $statement = $pdo->prepare(
        "INSERT INTO merchant_otp_codes (phone, code_hash, expires_at, attempts, consumed_at, signup_token_hash, signup_token_expires_at, created_at, updated_at)
         VALUES (?, 'consumed', now(), 0, now(), ?, now() + interval '15 minutes', now(), now())"
    );
    $statement->execute([$phone, hash('sha256', $token)]);

    return $token;
}

/** A live code row inside the test transaction; the code is always 123456. */
function liveMerchantOtpRow(string $phone): MerchantOtpCode
{
    return MerchantOtpCode::query()->create([
        'phone' => $phone,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(MerchantOtpService::CODE_TTL_MINUTES),
        'attempts' => 0,
    ]);
}

it('keeps the burnt attempt after a wrong code — the counter must survive the refusal', function () {
    $otp = liveMerchantOtpRow('+9607990011');

    expect(fn () => app(MerchantOtpService::class)->verify('+9607990011', '000000'))
        ->toThrow(InvalidOtpException::class);

    // The increment happens inside the verify transaction now; throwing from
    // inside it would roll the attempt back and make the cap unreachable.
    expect($otp->refresh()->attempts)->toBe(1);
});

it('makes a concurrent store guess WAIT on the code row instead of spending a stale attempt', function () {
    $pdo = signupRacePdo();
    signupRaceCleanup($pdo);

    $id = commitMerchantOtpRow($pdo, '+9607990001', '123456', attempts: MerchantOtpService::MAX_ATTEMPTS - 1);

    try {
        // The competing request holds the row and has spent the last
        // attempt — uncommitted, so a lock-free read still sees one left.
        $pdo->beginTransaction();
        $pdo->query("SELECT * FROM merchant_otp_codes WHERE id = {$id} FOR UPDATE");
        $pdo->exec('UPDATE merchant_otp_codes SET attempts = '.MerchantOtpService::MAX_ATTEMPTS." WHERE id = {$id}");

        // The guess must never even be evaluated against that stale snapshot.
        Hash::partialMock()->shouldReceive('check')->never();

        DB::statement("SET LOCAL lock_timeout = '400ms'");

        expect(fn () => app(MerchantOtpService::class)->verify('+9607990001', '123456'))
            ->toThrow(QueryException::class);

        $pdo->commit();

        // Cap holds for everyone once the competing attempt lands — even the
        // correct code is refused.
        expect(fn () => app(MerchantOtpService::class)->verify('+9607990001', '123456'))
            ->toThrow(TooManyOtpAttemptsException::class);
    } finally {
        signupRaceCleanup($pdo, releaseTestLocks: true);
    }
});

it('locks the code row inside a transaction for both verify and register', function () {
    liveMerchantOtpRow('+9607990012');

    $locking = [];
    DB::listen(function ($query) use (&$locking): void {
        if (str_contains($query->sql, 'for update')) {
            $locking[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        }
    });

    $token = app(MerchantOtpService::class)->verify('+9607990012', '123456');
    app(MerchantOtpService::class)->register($token, 'Race Mart', 'owner@racemart.mv', 'a-strong-password');

    expect($locking)->toHaveCount(2);

    foreach ($locking as $query) {
        expect($query['sql'])->toContain('merchant_otp_codes')
            // Level 1 is the RefreshDatabase wrapper: anything deeper is the
            // service's own transaction, which is where the lock must live.
            ->and($query['level'])->toBeGreaterThan(1);
    }
});

it('creates ONE store per verification when register is submitted twice', function () {
    liveMerchantOtpRow('+9607990013');
    $token = app(MerchantOtpService::class)->verify('+9607990013', '123456');

    app(MerchantOtpService::class)->register($token, 'Race Mart', 'first@racemart.mv', 'a-strong-password');

    // Same token, a DIFFERENT email — the email unique index would not stop
    // this one; only the single-use token does.
    expect(fn () => app(MerchantOtpService::class)->register($token, 'Race Mart', 'second@racemart.mv', 'a-strong-password'))
        ->toThrow(InvalidSignupTokenException::class);

    expect(Merchant::query()->where('contact_phone', '+9607990013')->count())->toBe(1)
        ->and(MerchantUser::query()->where('email', 'second@racemart.mv')->exists())->toBeFalse();
});

it('makes a double-submitted store register WAIT for the token holder rather than reading it live', function () {
    $pdo = signupRacePdo();
    signupRaceCleanup($pdo);

    $token = commitMerchantSignupToken($pdo, '+9607990002');

    try {
        // The winning register holds the token row and has cleared the token
        // — uncommitted, so a lock-free read would still see it as live and
        // mint a second store from one phone verification.
        $pdo->beginTransaction();
        $pdo->query("SELECT * FROM merchant_otp_codes WHERE phone = '+9607990002' FOR UPDATE");
        $pdo->exec("UPDATE merchant_otp_codes SET signup_token_hash = NULL, signup_token_expires_at = NULL WHERE phone = '+9607990002'");

        DB::statement("SET LOCAL lock_timeout = '400ms'");

        $completed = [];
        DB::listen(function ($query) use (&$completed): void {
            $completed[] = $query->sql;
        });

        expect(fn () => app(MerchantOtpService::class)->register($token, 'Race Mart', 'loser@racemart.mv', 'a-strong-password'))
            ->toThrow(QueryException::class);

        // The wait must happen on the READ. A lock-free read followed by a
        // blocked write looks identical from outside but has already decided
        // to build the store on stale state — so assert that no query against
        // the code row ever completed.
        expect(array_filter($completed, fn (string $sql): bool => str_contains($sql, 'merchant_otp_codes')))->toBeEmpty();

        expect(Merchant::query()->where('contact_phone', '+9607990002')->exists())->toBeFalse();

        $pdo->commit();

        expect(fn () => app(MerchantOtpService::class)->register($token, 'Race Mart', 'loser@racemart.mv', 'a-strong-password'))
            ->toThrow(InvalidSignupTokenException::class);

        expect(Merchant::query()->where('contact_phone', '+9607990002')->exists())->toBeFalse();
    } finally {
        signupRaceCleanup($pdo, releaseTestLocks: true);
    }
});

it('retries the slug when a rival store commits the one it just probed', function () {
    $pdo = signupRacePdo();
    signupRaceCleanup($pdo);

    liveMerchantOtpRow('+9607990014');
    $token = app(MerchantOtpService::class)->verify('+9607990014', '123456');

    // The probe-then-insert can never be atomic: a rival signup with the same
    // business name commits the probed slug in the gap. That is a retry, not
    // a 500.
    $rivalSlug = null;
    Merchant::creating(function (Merchant $merchant) use ($pdo, &$rivalSlug): void {
        if ($rivalSlug !== null) {
            return;
        }
        $rivalSlug = $merchant->slug;

        $statement = $pdo->prepare("INSERT INTO merchants (name, slug, status, channel, created_at, updated_at)
            VALUES ('Rival Mart', ?, 'draft', 'in_store', now(), now())");
        $statement->execute([$rivalSlug]);
    });

    try {
        $owner = app(MerchantOtpService::class)->register($token, 'Race Mart', 'owner@racemart.mv', 'a-strong-password');

        expect($rivalSlug)->toBe('race-mart')
            ->and($owner->merchant->slug)->toBe('race-mart-2')
            ->and($owner->merchant->status)->toBe('draft')
            // Still within the public store route's [a-z0-9-]{1,80} pattern.
            ->and($owner->merchant->slug)->toMatch('/^[a-z0-9-]{1,80}$/');
    } finally {
        signupRaceCleanup($pdo, releaseTestLocks: true);
    }
});

it('answers the clean email-taken 422 when a rival account wins the insert', function () {
    $pdo = signupRacePdo();
    signupRaceCleanup($pdo);

    $this->withHeader('Referer', 'http://localhost');

    $sms = new class implements SmsSender
    {
        public string $code = '';

        public function send(string $phone, string $message): void
        {
            preg_match('/\b(\d{6})\b/', $message, $matches);
            $this->code = $matches[1];
        }
    };
    $this->app->instance(SmsSender::class, $sms);

    $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607990003'])->assertOk();
    $token = $this->postJson('/api/merchant/signup/verify-otp', [
        'phone' => '+9607990003',
        'code' => $sms->code,
    ])->assertOk()->json('data.signup_token');

    // A rival signup commits the same owner email between this request's
    // exists() pre-check and its INSERT. The unique index is the real check,
    // and it must answer the same 422 the pre-check gives — never a 500.
    $rival = false;
    MerchantUser::creating(function () use ($pdo, &$rival): void {
        if ($rival) {
            return;
        }
        $rival = true;

        $merchantId = (int) $pdo->query("INSERT INTO merchants (name, slug, status, channel, created_at, updated_at)
            VALUES ('Rival Mart', 'race-mart-rival', 'draft', 'in_store', now(), now()) RETURNING id")->fetchColumn();

        $pdo->exec("INSERT INTO merchant_users (merchant_id, name, email, password, role, is_active, created_at, updated_at)
            VALUES ({$merchantId}, 'Rival', 'owner@racemart.mv', 'x', 'owner', true, now(), now())");
    });

    try {
        $this->postJson('/api/merchant/signup/register', [
            'signup_token' => $token,
            'business_name' => 'Race Mart',
            'email' => 'owner@racemart.mv',
            'password' => 'a-strong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'email_already_registered');

        expect($rival)->toBeTrue()
            // The half-built store rolls back with it — no orphan draft.
            ->and(Merchant::query()->where('contact_phone', '+9607990003')->exists())->toBeFalse();
    } finally {
        signupRaceCleanup($pdo, releaseTestLocks: true);
    }
});

it('surfaces a duplicate email as the domain error, not a query exception', function () {
    MerchantUser::factory()->create(['email' => 'taken@racemart.mv']);

    liveMerchantOtpRow('+9607990015');
    $token = app(MerchantOtpService::class)->verify('+9607990015', '123456');

    expect(fn () => app(MerchantOtpService::class)->register($token, 'Race Mart', 'taken@racemart.mv', 'a-strong-password'))
        ->toThrow(EmailAlreadyRegisteredException::class);
});
