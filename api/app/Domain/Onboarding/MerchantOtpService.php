<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\SmsSender;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Models\Merchant;
use App\Models\MerchantOtpCode;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Merchant self-signup phone verification (§1 decision 2026-08-15) — the
 * merchant-scoped mirror of the customer OtpService: request() sends a
 * 6-digit code (10-minute expiry, 5 attempts), verify() redeems it for a
 * short-lived signup token, register() redeems that token for the actual
 * store: a DRAFT merchant plus its owner MerchantUser.
 *
 * Storage is merchant_otp_codes — deliberately NOT the customer otp_codes
 * table, so the two flows never share or race over each other's rows. The
 * shared pieces are the storage rules (bcrypt codes, sha256 tokens) and the
 * SmsSender delivery interface. The generic OTP exceptions are reused from
 * the Customers domain; they carry no customer coupling.
 */
final readonly class MerchantOtpService
{
    public const int CODE_TTL_MINUTES = 10;

    public const int MAX_ATTEMPTS = 5;

    public const int SIGNUP_TOKEN_TTL_MINUTES = 15;

    public function __construct(private SmsSender $sms) {}

    /**
     * Issues a fresh code for the phone, superseding any live one. Behaves
     * identically whether or not the phone already belongs to a merchant
     * account — anything else would let a caller enumerate numbers.
     */
    public function request(string $phone): void
    {
        $now = CarbonImmutable::now('UTC');
        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($phone, $code, $now): void {
            // Supersede rather than delete: the row trail evidences how often
            // codes were requested for a number.
            MerchantOtpCode::query()
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            MerchantOtpCode::query()->create([
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => $now->addMinutes(self::CODE_TTL_MINUTES),
                'attempts' => 0,
            ]);
        });

        $this->sms->send($phone, sprintf(
            'Your %s store signup code is %s. It expires in %d minutes.',
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

        $otp = MerchantOtpCode::query()
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
     * Redeems the signup token: creates the DRAFT merchant (slug uniquified
     * from the business name, verified phone stored as the contact number)
     * and its owner account in one transaction. The token is single-use.
     *
     * @return MerchantUser the owner account, merchant relation loaded
     */
    public function register(string $signupToken, string $businessName, string $email, string $password): MerchantUser
    {
        $now = CarbonImmutable::now('UTC');

        $otp = MerchantOtpCode::query()
            ->where('signup_token_hash', hash('sha256', $signupToken))
            ->first();

        if ($otp === null || $otp->signup_token_expires_at === null || $otp->signup_token_expires_at->isBefore($now)) {
            throw InvalidSignupTokenException::make();
        }

        if (MerchantUser::query()->where('email', $email)->exists()) {
            throw EmailAlreadyRegisteredException::forEmail($email);
        }

        return DB::transaction(function () use ($otp, $businessName, $email, $password): MerchantUser {
            $otp->forceFill([
                'signup_token_hash' => null,
                'signup_token_expires_at' => null,
            ])->save();

            $merchant = Merchant::query()->create([
                'name' => $businessName,
                'slug' => $this->uniqueSlug($businessName),
                'status' => 'draft',
                'channel' => 'in_store',
                'contact_email' => $email,
                'contact_phone' => $otp->phone,
                'setup_state' => (object) [],
            ]);

            $owner = MerchantUser::query()->create([
                'merchant_id' => $merchant->id,
                'name' => $businessName,
                'email' => $email,
                'password' => $password,
                'role' => 'owner',
                'is_active' => true,
            ]);

            return $owner->setRelation('merchant', $merchant);
        });
    }

    /**
     * Slug from the business name, uniquified with a numeric suffix. A name
     * that slugs to nothing (e.g. a fully Thaana name) falls back to
     * 'store'. Kept within the public route's [a-z0-9-]{1,80} pattern; the
     * unique index is the backstop for the create/create race.
     */
    private function uniqueSlug(string $businessName): string
    {
        $base = Str::limit(Str::slug($businessName), 60, '');

        if ($base === '') {
            $base = 'store';
        }

        $slug = $base;
        $suffix = 2;

        while (Merchant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
