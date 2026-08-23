<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Customers\CustomerAvatar;
use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\Msisdn;
use App\Domain\Customers\OtpService;
use App\Domain\Customers\PhoneAlreadyRegisteredException;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Domain\Mobile\IssuedMobileToken;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Http\Controllers\Controller;
use App\Http\Support\OtpRequestLimiter;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Passwordless customer sign-in for the app (customer round R1, decision
 * 2026-08-17): one proof of phone possession serves BOTH sign-in and signup.
 *
 * request → verify → (existing account: bearer token | unknown number:
 * signup token → register → bearer token). The OTP machinery is the web
 * signup's OtpService wholesale — same hashed codes, same locked single-use
 * redemption, same attempt caps, and the SAME per-phone SMS budget
 * (OtpRequestLimiter keys are shared across surfaces on purpose).
 *
 * Enumeration stance, inherited exactly: request() answers identically for
 * known and unknown numbers; only a caller who has TYPED THE CODE — proven
 * possession — learns whether an account exists, the same line the web
 * register() draws.
 *
 * Accounts created here are PASSWORDLESS: the row's password is 40 random
 * characters nobody knows. Known consequence, recorded in the plan: such an
 * account cannot use the web's password login until a web OTP sign-in (or a
 * set-password flow) ships — the phone app is its home.
 */
final class CustomerOtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly MobileTokenService $tokens,
    ) {}

    public function request(Request $request): JsonResponse
    {
        $this->normalisePhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:'.Msisdn::PATTERN],
        ]);

        if ($refusal = OtpRequestLimiter::hitOrRefuse($validated['phone'], (string) $request->ip())) {
            return $refusal;
        }

        $this->otp->request($validated['phone']);

        // One body for every phone — known and unknown are indistinguishable.
        return new JsonResponse([
            'data' => [
                'sent' => true,
                'expires_in_minutes' => OtpService::CODE_TTL_MINUTES,
            ],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->normalisePhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:'.Msisdn::PATTERN],
            'code' => ['required', 'string', 'digits:6'],
            // Required NOW, not at token time: the sign-in outcome mints the
            // token in this same request, and the device list is only as
            // honest as the names it is given.
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        try {
            $outcome = $this->otp->verifyForAccess($validated['phone'], $validated['code']);
        } catch (TooManyOtpAttemptsException) {
            return self::refusal(
                'Too many wrong codes. Request a fresh one and try again.',
                'otp_attempts_exceeded',
                422,
            );
        } catch (InvalidOtpException) {
            return self::refusal(
                'That code is not right or has expired. Check it, or request a fresh one.',
                'otp_invalid',
                422,
            );
        }

        $customer = $outcome->customer;

        if ($customer === null) {
            return new JsonResponse([
                'data' => [
                    'status' => 'registration_required',
                    'signup_token' => $outcome->signupToken,
                    'expires_in_minutes' => OtpService::SIGNUP_TOKEN_TTL_MINUTES,
                ],
            ]);
        }

        // Possession is proven, so naming the account's standing is
        // consistent with the stance above — and pretending the CORRECT code
        // was wrong would only send the owner in circles. The code is
        // already consumed either way.
        if (! $customer->mayUseMobileApp()) {
            return self::refusal(
                'This account cannot be signed in right now. Contact Manfaa support.',
                'account_unavailable',
                403,
            );
        }

        return $this->signedIn(
            $customer,
            $this->tokens->issue($customer, MobileAudience::Customer, $validated['device_name']),
        );
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'signup_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'device_name' => ['required', 'string', 'max:120'],
            // A friend's 6-digit customer code (referral programme). Only
            // the SHAPE is validated here; a well-formed code nobody holds
            // is silently ignored downstream — a mistyped referral must
            // never cost anyone their signup.
            'referral_code' => ['nullable', 'string', 'digits:6'],
        ]);

        try {
            $customer = $this->otp->register(
                $validated['signup_token'],
                $validated['name'],
                // Passwordless account: random, unknown to everyone — see
                // the class docblock for the recorded web-login consequence.
                Str::password(40),
                $validated['referral_code'] ?? null,
            );
        } catch (InvalidSignupTokenException) {
            return self::refusal(
                'That verification has expired. Start again with your phone number.',
                'signup_token_invalid',
                422,
            );
        } catch (PhoneAlreadyRegisteredException) {
            // Reachable only by racing verify(): the sign-in outcome would
            // otherwise have caught the account. Send them to sign in.
            return self::refusal(
                'This number already has an account. Sign in instead.',
                'phone_already_registered',
                409,
            );
        }

        return $this->signedIn(
            $customer,
            $this->tokens->issue($customer, MobileAudience::Customer, $validated['device_name']),
            created: true,
        );
    }

    /** The same signed-in shape the password endpoint answers. */
    private function signedIn(Customer $customer, IssuedMobileToken $issued, bool $created = false): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'status' => 'signed_in',
                'token' => $issued->plainTextToken,
                'expires_at' => $issued->expiresAt->toIso8601ZuluString(),
                'device_name' => $issued->deviceName,
                'customer' => [
                    'id' => $customer->getKey(),
                    'name' => $customer->name,
                    'customer_code' => $customer->customer_code,
                    // Cached beside name/code on the device so the top bar
                    // renders the picture offline. Null until one is set.
                    'avatar_url' => CustomerAvatar::url($customer),
                ],
            ],
        ], $created ? 201 : 200);
    }

    /** A refusal carrying its own code — NormalisesMobileErrors envelopes it. */
    private static function refusal(string $message, string $code, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], $status);
    }

    /**
     * Folds whatever was typed into the stored E.164 shape BEFORE validation
     * — same rule, same reason as the web controller: phone keys the OTP
     * row, the throttle and the account, so the forms must meet before use.
     * Unparseable input is left as typed for the rule to refuse.
     */
    private function normalisePhone(Request $request): void
    {
        $raw = $request->input('phone');

        if (is_string($raw)) {
            $request->merge(['phone' => Msisdn::normalise($raw) ?? $raw]);
        }
    }
}
