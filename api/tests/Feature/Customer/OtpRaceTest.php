<?php

declare(strict_types=1);

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\OtpService;
use App\Domain\Customers\SmsSender;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Models\Customer;
use App\Models\OtpCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Concurrency proofs for the customer signup OTP (§12 Phase 3). Same shape
 * as StaffOwnerRaceTest: a SECOND, real Postgres session plays the competing
 * request, because the failure being tested — two requests acting on one
 * stale read — cannot be reproduced inside a single connection.
 *
 * Phones live in a +96077900xx block used by no other test: rows committed by
 * the second session survive the RefreshDatabase rollback and are cleaned up
 * explicitly.
 */
function otpRacePdo(): PDO
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

function otpRaceCleanup(PDO $pdo, bool $releaseTestLocks = false): void
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

        $pdo->exec("DELETE FROM customers WHERE phone LIKE '+96077900%'");
        $pdo->exec("DELETE FROM otp_codes WHERE phone LIKE '+96077900%'");
    } catch (PDOException) {
        // A failing test can still hold row locks; leftovers are wiped by
        // the next migrate:fresh and must not mask the real failure.
    }
}

/** Commits a live code row exactly as request() would have written it. */
function commitOtpRow(PDO $pdo, string $phone, string $code, int $attempts = 0): int
{
    $statement = $pdo->prepare(
        "INSERT INTO otp_codes (phone, code_hash, expires_at, attempts, created_at, updated_at)
         VALUES (?, ?, now() + interval '10 minutes', ?, now(), now()) RETURNING id"
    );
    $statement->execute([$phone, Hash::make($code), $attempts]);

    return (int) $statement->fetchColumn();
}

/** Commits a verified row carrying a live signup token; returns the token. */
function commitSignupToken(PDO $pdo, string $phone): string
{
    $token = Str::random(48);

    $statement = $pdo->prepare(
        "INSERT INTO otp_codes (phone, code_hash, expires_at, attempts, consumed_at, signup_token_hash, signup_token_expires_at, created_at, updated_at)
         VALUES (?, 'consumed', now(), 0, now(), ?, now() + interval '15 minutes', now(), now())"
    );
    $statement->execute([$phone, hash('sha256', $token)]);

    return $token;
}

/** A live code row inside the test transaction, plus its plaintext code. */
function liveOtpRow(string $phone): OtpCode
{
    return OtpCode::query()->create([
        'phone' => $phone,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(OtpService::CODE_TTL_MINUTES),
        'attempts' => 0,
    ]);
}

it('keeps the burnt attempt after a wrong code — the counter must survive the refusal', function () {
    $otp = liveOtpRow('+9607790011');

    expect(fn () => app(OtpService::class)->verify('+9607790011', '000000'))
        ->toThrow(InvalidOtpException::class);

    // The increment now happens inside the verify transaction; throwing from
    // inside it would roll the attempt back and make the cap unreachable.
    expect($otp->refresh()->attempts)->toBe(1);

    for ($guess = 2; $guess <= OtpService::MAX_ATTEMPTS; $guess++) {
        try {
            app(OtpService::class)->verify('+9607790011', '000000');
        } catch (InvalidOtpException|TooManyOtpAttemptsException) {
            // expected
        }
    }

    expect($otp->refresh()->attempts)->toBe(OtpService::MAX_ATTEMPTS);

    expect(fn () => app(OtpService::class)->verify('+9607790011', '123456'))
        ->toThrow(TooManyOtpAttemptsException::class);
});

it('makes a concurrent guess WAIT on the code row instead of spending a stale attempt', function () {
    $pdo = otpRacePdo();
    otpRaceCleanup($pdo);

    // One attempt left on a committed, live code.
    $id = commitOtpRow($pdo, '+9607790001', '123456', attempts: OtpService::MAX_ATTEMPTS - 1);

    try {
        // The competing request holds the row and has spent that last
        // attempt — but has not committed.
        $pdo->beginTransaction();
        $pdo->query("SELECT * FROM otp_codes WHERE id = {$id} FOR UPDATE");
        $pdo->exec('UPDATE otp_codes SET attempts = '.OtpService::MAX_ATTEMPTS." WHERE id = {$id}");

        // No guess may be EVALUATED against the stale attempts snapshot: the
        // verification must queue behind the lock (a wait, surfaced here as a
        // timeout), never reach the hash comparison.
        Hash::partialMock()->shouldReceive('check')->never();

        DB::statement("SET LOCAL lock_timeout = '400ms'");

        expect(fn () => app(OtpService::class)->verify('+9607790001', '123456'))
            ->toThrow(QueryException::class);

        // The competing attempt lands. The cap now holds for everyone —
        // even the CORRECT code is refused, which is the whole point of a
        // 5-attempt cap that parallelism cannot widen.
        $pdo->commit();

        expect(fn () => app(OtpService::class)->verify('+9607790001', '123456'))
            ->toThrow(TooManyOtpAttemptsException::class);
    } finally {
        otpRaceCleanup($pdo, releaseTestLocks: true);
    }
});

it('locks the code row inside a transaction for both verify and register', function () {
    liveOtpRow('+9607790012');

    $locking = [];
    DB::listen(function ($query) use (&$locking): void {
        if (str_contains($query->sql, 'for update')) {
            $locking[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        }
    });

    $token = app(OtpService::class)->verify('+9607790012', '123456');
    app(OtpService::class)->register($token, 'Aishath Manike', 'a-strong-password');

    expect($locking)->toHaveCount(2);

    foreach ($locking as $query) {
        expect($query['sql'])->toContain('otp_codes')
            // Level 1 is the RefreshDatabase wrapper: anything deeper is the
            // service's own transaction, which is where the lock must live.
            ->and($query['level'])->toBeGreaterThan(1);
    }
});

it('mints ONE account per verification when register is submitted twice', function () {
    liveOtpRow('+9607790013');
    $token = app(OtpService::class)->verify('+9607790013', '123456');

    app(OtpService::class)->register($token, 'Aishath Manike', 'a-strong-password');

    expect(fn () => app(OtpService::class)->register($token, 'Second Tap', 'a-strong-password'))
        ->toThrow(InvalidSignupTokenException::class);

    expect(Customer::query()->where('phone', '+9607790013')->count())->toBe(1);
});

it('makes a double-submitted register WAIT for the token holder rather than reading it live', function () {
    $pdo = otpRacePdo();
    otpRaceCleanup($pdo);

    $token = commitSignupToken($pdo, '+9607790002');

    try {
        // The winning register holds the token row and has cleared the token
        // — uncommitted, so a lock-free read would still see it as live.
        $pdo->beginTransaction();
        $pdo->query("SELECT * FROM otp_codes WHERE phone = '+9607790002' FOR UPDATE");
        $pdo->exec("UPDATE otp_codes SET signup_token_hash = NULL, signup_token_expires_at = NULL WHERE phone = '+9607790002'");

        DB::statement("SET LOCAL lock_timeout = '400ms'");

        $completed = [];
        DB::listen(function ($query) use (&$completed): void {
            $completed[] = $query->sql;
        });

        expect(fn () => app(OtpService::class)->register($token, 'Second Tap', 'a-strong-password'))
            ->toThrow(QueryException::class);

        // The wait must happen on the READ. A lock-free read followed by a
        // blocked write looks the same from outside but has already decided
        // to create the account on stale state — so assert that no query
        // against the code row ever completed.
        expect(array_filter($completed, fn (string $sql): bool => str_contains($sql, 'otp_codes')))->toBeEmpty();

        expect(Customer::query()->where('phone', '+9607790002')->exists())->toBeFalse();

        // Once the winner commits, the loser gets the ordinary invalid-token
        // answer — never a second account.
        $pdo->commit();

        expect(fn () => app(OtpService::class)->register($token, 'Second Tap', 'a-strong-password'))
            ->toThrow(InvalidSignupTokenException::class);

        expect(Customer::query()->where('phone', '+9607790002')->exists())->toBeFalse();
    } finally {
        otpRaceCleanup($pdo, releaseTestLocks: true);
    }
});

it('answers the clean duplicate-phone 422 when a rival signup wins the insert', function () {
    $pdo = otpRacePdo();
    otpRaceCleanup($pdo);

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

    $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607790003'])->assertOk();
    $token = $this->postJson('/api/customer/auth/verify-otp', [
        'phone' => '+9607790003',
        'code' => $sms->code,
    ])->assertOk()->json('data.signup_token');

    // A rival request commits the same phone in the instant between this
    // request's exists() pre-check and its INSERT. The unique index — not the
    // pre-check — is what actually holds, and it must surface as the same
    // 422 the pre-check gives, never a 500.
    $rival = false;
    Customer::creating(function () use ($pdo, &$rival): void {
        if ($rival) {
            return;
        }
        $rival = true;

        // '771111' is in the reserved range, so a generated code can never
        // collide with it — the only violation here is the phone.
        $pdo->exec("INSERT INTO customers (customer_code, phone, name, status, kyc_status, created_at, updated_at)
            VALUES ('771111', '+9607790003', 'Rival', 'active', 'none', now(), now())");
    });

    try {
        $this->postJson('/api/customer/auth/register', [
            'signup_token' => $token,
            'name' => 'Aishath Manike',
            'password' => 'a-strong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'phone_already_registered');

        expect($rival)->toBeTrue();
    } finally {
        otpRaceCleanup($pdo, releaseTestLocks: true);
    }
});

it('draws another customer code when a rival signup takes the one it drew', function () {
    $pdo = otpRacePdo();
    otpRaceCleanup($pdo);

    liveOtpRow('+9607790004');
    $token = app(OtpService::class)->verify('+9607790004', '123456');

    $stolen = null;
    Customer::creating(function (Customer $customer) use ($pdo, &$stolen): void {
        if ($stolen !== null) {
            return;
        }
        $stolen = $customer->customer_code;

        // A rival account commits the very code this one just drew.
        $statement = $pdo->prepare("INSERT INTO customers (customer_code, phone, name, status, kyc_status, created_at, updated_at)
            VALUES (?, '+9607790005', 'Rival', 'active', 'none', now(), now())");
        $statement->execute([$stolen]);
    });

    try {
        $customer = app(OtpService::class)->register($token, 'Aishath Manike', 'a-strong-password');

        // A 1-in-700,000 coincidence is not an error: redraw and carry on.
        expect($stolen)->not->toBeNull()
            ->and($customer->customer_code)->not->toBe($stolen)
            ->and($customer->customer_code)->toMatch('/^[1-68]\d{5}$/');
    } finally {
        otpRaceCleanup($pdo, releaseTestLocks: true);
    }
});
