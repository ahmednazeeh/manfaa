<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Customer;
use App\Models\OtpCode;
use Carbon\CarbonImmutable;
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
 * SMS delivery goes through the SmsSender interface — see its docblock for
 * the §14 provider swap point.
 */
final readonly class OtpService
{
    public const int CODE_TTL_MINUTES = 10;

    public const int MAX_ATTEMPTS = 5;

    public const int SIGNUP_TOKEN_TTL_MINUTES = 15;

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
     * @return string the plaintext signup token (never stored)
     */
    public function verify(string $phone, string $code): string
    {
        $now = CarbonImmutable::now('UTC');

        $otp = OtpCode::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($otp === null || $otp->expires_at->isBefore($now)) {
            throw InvalidOtpException::forPhone($phone);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            throw TooManyOtpAttemptsException::forPhone($phone);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                throw TooManyOtpAttemptsException::forPhone($phone);
            }

            throw InvalidOtpException::forPhone($phone);
        }

        $token = Str::random(48);

        $otp->forceFill([
            'consumed_at' => $now,
            'signup_token_hash' => hash('sha256', $token),
            'signup_token_expires_at' => $now->addMinutes(self::SIGNUP_TOKEN_TTL_MINUTES),
        ])->save();

        return $token;
    }

    /**
     * Redeems the signup token: creates the customer with a generated
     * 6-digit code and the phone marked verified. The token is single-use —
     * it is cleared in the same transaction that creates the account.
     */
    public function register(string $signupToken, string $name, string $password): Customer
    {
        $now = CarbonImmutable::now('UTC');

        $otp = OtpCode::query()
            ->where('signup_token_hash', hash('sha256', $signupToken))
            ->first();

        if ($otp === null || $otp->signup_token_expires_at === null || $otp->signup_token_expires_at->isBefore($now)) {
            throw InvalidSignupTokenException::make();
        }

        if (Customer::query()->where('phone', $otp->phone)->exists()) {
            throw PhoneAlreadyRegisteredException::forPhone($otp->phone);
        }

        return DB::transaction(function () use ($otp, $name, $password, $now): Customer {
            $otp->forceFill([
                'signup_token_hash' => null,
                'signup_token_expires_at' => null,
            ])->save();

            return Customer::query()->create([
                'customer_code' => Customer::generateCode(),
                'phone' => $otp->phone,
                'phone_verified_at' => $now,
                'name' => $name,
                'password' => $password,
                'status' => 'active',
                'kyc_status' => 'none',
            ]);
        });
    }
}
