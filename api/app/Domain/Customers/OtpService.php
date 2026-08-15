<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Customer;
use App\Models\OtpCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Customer signup phone verification (§10 apps/web, §12 Phase 3).
 *
 * Flow: request() sends a 6-digit code (10-minute expiry, 5 attempts);
 * verify() redeems it for a short-lived signup token; register() redeems
 * that token for the actual account, marking the phone verified.
 *
 * Codes and tokens are never stored in the clear: codes are bcrypt-hashed
 * (they must survive online guessing), tokens are sha256-hashed (they are
 * high-entropy, so a fast hash is safe and allows indexed lookup).
 *
 * Both redemption steps are single-use under concurrency: verify() and
 * register() take a FOR UPDATE lock on the code row and re-assert their
 * precondition inside it, so parallel requests are serialised rather than
 * each acting on the same stale read (see each method for what that
 * prevents).
 *
 * SMS delivery goes through the SmsSender interface — see its docblock for
 * the §14 provider swap point.
 */
final readonly class OtpService
{
    public const int CODE_TTL_MINUTES = 10;

    public const int MAX_ATTEMPTS = 5;

    public const int SIGNUP_TOKEN_TTL_MINUTES = 15;

    /** Draws of a fresh 6-digit code before a collision is treated as real. */
    private const int CODE_DRAWS = 5;

    private const string OUTCOME_VERIFIED = 'verified';

    private const string OUTCOME_INVALID = 'invalid';

    private const string OUTCOME_EXHAUSTED = 'exhausted';

    public function __construct(private SmsSender $sms) {}

    /**
     * Issues a fresh code for the phone, superseding any live one (a phone
     * has at most one redeemable code). Deliberately does not care whether
     * the phone already has an account — behaving differently would let a
     * caller enumerate registered numbers.
     */
    public function request(string $phone): void
    {
        $now = CarbonImmutable::now('UTC');
        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($phone, $code, $now): void {
            // Supersede rather than delete: the row trail evidences how often
            // codes were requested for a number.
            OtpCode::query()
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            OtpCode::query()->create([
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => $now->addMinutes(self::CODE_TTL_MINUTES),
                'attempts' => 0,
            ]);
        });

        $this->sms->send($phone, sprintf(
            'Your %s verification code is %s. It expires in %d minutes.',
            (string) config('app.name'),
            $code,
            self::CODE_TTL_MINUTES,
        ));
    }

    /**
     * Verifies the code and mints the signup token the register step redeems.
     *
     * The read, the attempt-cap check, the increment and the consumption all
     * run inside ONE transaction with the code row locked FOR UPDATE. Without
     * the lock, N requests fired together all read `attempts` before any of
     * them writes, all pass the cap pre-check, and a 5-guess cap becomes an
     * N-guess cap — the whole point of the cap is that it cannot be widened
     * by parallelism.
     *
     * The outcome is RETURNED from the transaction and thrown after it
     * commits: throwing from inside would roll the attempt increment back and
     * hand a guesser unlimited free tries.
     *
     * @return string the plaintext signup token (never stored)
     */
    public function verify(string $phone, string $code): string
    {
        $now = CarbonImmutable::now('UTC');

        /** @var array{0: string, 1: string|null} $result */
        $result = DB::transaction(function () use ($phone, $code, $now): array {
            $otp = OtpCode::query()
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->expires_at->isBefore($now)) {
                return [self::OUTCOME_INVALID, null];
            }

            // Re-read under the lock: a concurrent request may have burned
            // the last attempt while this one was queued behind it.
            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                return [self::OUTCOME_EXHAUSTED, null];
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return [
                    $otp->attempts >= self::MAX_ATTEMPTS ? self::OUTCOME_EXHAUSTED : self::OUTCOME_INVALID,
                    null,
                ];
            }

            $token = Str::random(48);

            $otp->forceFill([
                'consumed_at' => $now,
                'signup_token_hash' => hash('sha256', $token),
                'signup_token_expires_at' => $now->addMinutes(self::SIGNUP_TOKEN_TTL_MINUTES),
            ])->save();

            return [self::OUTCOME_VERIFIED, $token];
        });

        return match ($result[0]) {
            self::OUTCOME_VERIFIED => (string) $result[1],
            self::OUTCOME_EXHAUSTED => throw TooManyOtpAttemptsException::forPhone($phone),
            default => throw InvalidOtpException::forPhone($phone),
        };
    }

    /**
     * Redeems the signup token: creates the customer with a generated
     * 6-digit code and the phone marked verified. The token is single-use —
     * it is cleared in the same transaction that creates the account.
     *
     * The token row is locked FOR UPDATE and the token re-asserted INSIDE
     * that lock: a double-submitted register (impatient tap, retried fetch)
     * would otherwise have both requests read one live token and try to mint
     * two accounts from a single phone verification. The loser now finds the
     * token already cleared and gets the ordinary invalid-token answer.
     */
    public function register(string $signupToken, string $name, string $password): Customer
    {
        $now = CarbonImmutable::now('UTC');
        $tokenHash = hash('sha256', $signupToken);

        return DB::transaction(function () use ($tokenHash, $name, $password, $now): Customer {
            $otp = OtpCode::query()
                ->where('signup_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->signup_token_expires_at === null || $otp->signup_token_expires_at->isBefore($now)) {
                throw InvalidSignupTokenException::make();
            }

            if (Customer::query()->where('phone', $otp->phone)->exists()) {
                throw PhoneAlreadyRegisteredException::forPhone($otp->phone);
            }

            $otp->forceFill([
                'signup_token_hash' => null,
                'signup_token_expires_at' => null,
            ])->save();

            return $this->createCustomer((string) $otp->phone, $name, $password, $now);
        });
    }

    /**
     * Inserts the account, treating the two unique indexes as what they are:
     * `customers_phone_unique` is a duplicate signup (answer the clean 422,
     * never a 500), `customers_customer_code_unique` is a 1-in-700,000
     * coincidence between two simultaneous signups (draw another code).
     *
     * Each attempt runs in a nested transaction — Laravel turns that into a
     * SAVEPOINT, so a failed INSERT rolls back only itself and leaves the
     * outer transaction usable; in Postgres any error otherwise poisons the
     * whole transaction and every later statement fails.
     */
    private function createCustomer(string $phone, string $name, string $password, CarbonImmutable $now): Customer
    {
        $draw = 1;

        while (true) {
            try {
                return DB::transaction(fn (): Customer => Customer::query()->create([
                    'customer_code' => Customer::generateCode(),
                    'phone' => $phone,
                    'phone_verified_at' => $now,
                    'name' => $name,
                    'password' => $password,
                    'status' => 'active',
                    'kyc_status' => 'none',
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'customers_phone_unique')) {
                    throw PhoneAlreadyRegisteredException::forPhone($phone);
                }

                if (! str_contains($e->getMessage(), 'customers_customer_code_unique') || $draw >= self::CODE_DRAWS) {
                    throw $e;
                }

                $draw++;
            }
        }
    }
}
