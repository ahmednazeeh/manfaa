<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\SmsSender;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Domain\MerchantAccess\RolePresetService;
use App\Models\Merchant;
use App\Models\MerchantOtpCode;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** Slug candidates tried before the insert is treated as a real failure. */
    private const int SLUG_ATTEMPTS = 4;

    private const string OUTCOME_VERIFIED = 'verified';

    private const string OUTCOME_INVALID = 'invalid';

    private const string OUTCOME_EXHAUSTED = 'exhausted';

    public function __construct(private SmsSender $sms, private RolePresetService $roles) {}

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
     * Concurrency stance mirrors the customer flow exactly: the read, the
     * cap check, the increment and the consumption run in ONE transaction
     * with the code row locked FOR UPDATE, so parallel guesses queue behind
     * each other instead of all passing the cap pre-check on the same stale
     * `attempts` read. The outcome is returned and thrown after commit —
     * throwing inside would roll the increment back and make the cap free.
     *
     * @return string the plaintext signup token (never stored)
     */
    public function verify(string $phone, string $code): string
    {
        $now = CarbonImmutable::now('UTC');

        /** @var array{0: string, 1: string|null} $result */
        $result = DB::transaction(function () use ($phone, $code, $now): array {
            $otp = MerchantOtpCode::query()
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
     * Redeems the signup token: creates the DRAFT merchant (slug uniquified
     * from the business name, verified phone stored as the contact number)
     * and its owner account in one transaction. The token is single-use.
     *
     * @param  string|null  $businessNameDv  the store's own name in Thaana,
     *                                       shown to Dhivehi visitors
     * @param  int|null  $validationWindowDays  how long the store holds a
     *                                          sale before its cashback
     *                                          validates. NULL means "say
     *                                          nothing", and the store is
     *                                          created on the platform
     *                                          default exactly as before
     *                                          this field existed. The
     *                                          CEILING is enforced at the
     *                                          HTTP edge by the rule the
     *                                          preferences screen shares
     *                                          (App\Rules\ValidationWindowDays).
     * @return MerchantUser the owner account, merchant relation loaded
     */
    public function register(string $signupToken, string $businessName, string $email, string $password, ?string $businessNameDv = null, ?int $validationWindowDays = null): MerchantUser
    {
        $now = CarbonImmutable::now('UTC');
        $tokenHash = hash('sha256', $signupToken);

        return DB::transaction(function () use ($tokenHash, $businessName, $businessNameDv, $email, $password, $validationWindowDays, $now): MerchantUser {
            // Lock the code row and re-assert the token INSIDE the lock: a
            // double-submitted register would otherwise have both requests
            // read the same live token and mint two stores (two different
            // emails clear the unique index) from one phone verification.
            $otp = MerchantOtpCode::query()
                ->where('signup_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->signup_token_expires_at === null || $otp->signup_token_expires_at->isBefore($now)) {
                throw InvalidSignupTokenException::make();
            }

            if (MerchantUser::query()->where('email', $email)->exists()) {
                throw EmailAlreadyRegisteredException::forEmail($email);
            }

            $otp->forceFill([
                'signup_token_hash' => null,
                'signup_token_expires_at' => null,
            ])->save();

            $merchant = $this->createMerchant($businessName, $email, (string) $otp->phone, $businessNameDv, $validationWindowDays);

            // The store's Owner / Manager / Staff roles, inside the SAME
            // transaction as the store and its first account: a signup that
            // half-failed would otherwise leave an owner pointing at no
            // role, and a null role is refused by every gate — the account
            // would exist and be able to do nothing at all.
            $roles = $this->roles->provision($merchant);

            try {
                $owner = MerchantUser::query()->create([
                    'merchant_id' => $merchant->id,
                    'name' => $businessName,
                    'email' => $email,
                    'password' => $password,
                    'merchant_role_id' => $roles[RolePresetService::OWNER]->id,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // The unique index is the real check; the exists() above only
                // catches what was already committed when this request read.
                // Two signups racing on one email must both get the clean 422,
                // never a 500 (and the whole store creation rolls back).
                if (str_contains($e->getMessage(), 'merchant_users_email_unique')) {
                    throw EmailAlreadyRegisteredException::forEmail($email);
                }

                throw $e;
            }

            return $owner->setRelation('merchant', $merchant);
        });
    }

    /**
     * Creates the DRAFT store, retrying the slug when the unique index
     * refuses it.
     *
     * The probe-then-insert in slugCandidate() cannot be atomic — between the
     * probe and the INSERT another signup with the same business name can
     * commit the very slug it picked. Each insert therefore runs in a nested
     * transaction (Laravel emits a SAVEPOINT), so a collision rolls back only
     * the failed INSERT and the next probe — which now SEES the winner's
     * committed row — picks the next free suffix. A last resort appends a
     * random suffix so a pathological loop still terminates with a usable
     * store rather than a 500.
     */
    private function createMerchant(string $businessName, string $email, string $phone, ?string $businessNameDv = null, ?int $validationWindowDays = null): Merchant
    {
        $base = $this->slugBase($businessName);
        $attempt = 1;

        // The key is OMITTED when the merchant said nothing, never sent as
        // null: PlatformServiceProvider fills the platform default only for
        // an attribute that is absent, and a null would land as a null in a
        // NOT NULL column.
        $window = $validationWindowDays === null ? [] : ['validation_window_days' => $validationWindowDays];

        while (true) {
            $slug = $attempt < self::SLUG_ATTEMPTS
                ? $this->slugCandidate($base)
                : $base.'-'.Str::lower(Str::random(6));

            try {
                return DB::transaction(fn (): Merchant => Merchant::query()->create($window + [
                    'name' => $businessName,
                    'name_dv' => $businessNameDv,
                    // The slug stays derived from the LATIN name only, so
                    // store URLs remain ASCII whatever the store calls
                    // itself in Thaana.
                    'slug' => $slug,
                    'status' => 'draft',
                    'channel' => 'in_store',
                    'contact_email' => $email,
                    'contact_phone' => $phone,
                    'setup_state' => (object) [],
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'merchants_slug_unique') || $attempt >= self::SLUG_ATTEMPTS) {
                    throw $e;
                }

                $attempt++;
            }
        }
    }

    /**
     * Slug stem from the business name. A name that slugs to nothing (e.g. a
     * fully Thaana name) falls back to 'store'. Kept short enough that the
     * suffix still fits the public route's [a-z0-9-]{1,80} pattern.
     */
    private function slugBase(string $businessName): string
    {
        $base = Str::limit(Str::slug($businessName), 60, '');

        return $base === '' ? 'store' : $base;
    }

    /** First slug not currently taken: the stem, then -2, -3, … */
    private function slugCandidate(string $base): string
    {
        $slug = $base;
        $suffix = 2;

        while (Merchant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
