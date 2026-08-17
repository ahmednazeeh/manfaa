<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\Msisdn;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Onboarding\EmailAlreadyRegisteredException;
use App\Domain\Onboarding\MerchantOtpService;
use App\Http\Controllers\Controller;
use App\Http\Support\MerchantOtpRequestLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store self-signup from the merchant APP (merchant app MR1): the same
 * request-otp → verify-otp → register flow the web SignupController mounts,
 * on the same MerchantOtpService — one domain, two doors.
 *
 * The ONE divergence from the web flow, and the reason this controller
 * exists: register cannot log in a session a phone will never hold, so it
 * MINTS a merchant mobile token instead (audience merchant, device_name
 * required exactly like /merchant/auth/token) and answers the same signed-in
 * shape as MerchantTokenController's 201 — the app lands signed in as the
 * draft store's owner and goes straight to the setup wizard.
 *
 * Enumeration stance inherited unchanged: request-otp answers 200 with the
 * same body whether or not the phone is known, verify-otp never says WHY a
 * code failed; only register — reached exclusively with OTP-proven phone
 * possession — may say an email is taken.
 *
 * Refusals carry their own stable codes (otp_invalid, otp_attempts_exceeded,
 * signup_token_invalid, email_already_registered) as top-level payloads the
 * NormalisesMobileErrors envelope lifts into error.code — the same codes the
 * web flow expresses as field messages, made machine-readable the way the
 * customer app's OTP flow already publishes them (docs/mobile-api-guide.md).
 */
final class MerchantSignupController extends Controller
{
    public function __construct(
        private readonly MerchantOtpService $otp,
        private readonly MobileTokenService $tokens,
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $this->normalisePhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:'.Msisdn::PATTERN],
        ]);

        // The SAME budget as the web signup (MerchantOtpRequestLimiter keys
        // are shared across surfaces on purpose); the route throttle is the
        // coarse backstop.
        if ($refusal = MerchantOtpRequestLimiter::hitOrRefuse($validated['phone'], (string) $request->ip())) {
            return $refusal;
        }

        $this->otp->request($validated['phone']);

        // One body for every phone — known and unknown are indistinguishable.
        return new JsonResponse([
            'data' => [
                'sent' => true,
                'expires_in_minutes' => MerchantOtpService::CODE_TTL_MINUTES,
            ],
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $this->normalisePhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:'.Msisdn::PATTERN],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $token = $this->otp->verify($validated['phone'], $validated['code']);
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

        return new JsonResponse([
            'data' => [
                'signup_token' => $token,
                'expires_in_minutes' => MerchantOtpService::SIGNUP_TOKEN_TTL_MINUTES,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'signup_token' => ['required', 'string'],
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'business_name_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        try {
            $owner = $this->otp->register(
                $validated['signup_token'],
                $validated['business_name'],
                $validated['email'],
                $validated['password'],
                blank($validated['business_name_dv'] ?? null) ? null : trim((string) $validated['business_name_dv']),
            );
        } catch (InvalidSignupTokenException) {
            return self::refusal(
                'That verification has expired. Start again with your phone number.',
                'signup_token_invalid',
                422,
            );
        } catch (EmailAlreadyRegisteredException) {
            return self::refusal(
                'That email already has a merchant account. Sign in instead.',
                'email_already_registered',
                422,
            );
        }

        // The web logs a session in here; a phone holds a bearer token
        // instead. Same shape as the password sign-in's 201, so the app
        // parses ONE payload whichever door earned the token.
        $issued = $this->tokens->issue($owner, MobileAudience::Merchant, $validated['device_name']);

        return new JsonResponse(
            ['data' => MerchantTokenController::signedInPayload($owner, $issued)],
            201,
        );
    }

    /** A refusal carrying its own code — NormalisesMobileErrors envelopes it. */
    private static function refusal(string $message, string $code, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], $status);
    }

    /**
     * Folds whatever was typed into the stored E.164 shape BEFORE validation
     * — same rule, same reason as the web controller: phone keys the OTP
     * row, the throttle and the store's contact number, so the forms must
     * meet before use. Unparseable input is left as typed for the rule to
     * refuse.
     */
    private function normalisePhone(Request $request): void
    {
        $raw = $request->input('phone');

        if (is_string($raw)) {
            $request->merge(['phone' => Msisdn::normalise($raw) ?? $raw]);
        }
    }
}
