<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\Msisdn;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Domain\Onboarding\EmailAlreadyRegisteredException;
use App\Domain\Onboarding\MerchantOtpService;
use App\Domain\Onboarding\SignupOptions;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantUserResource;
use App\Http\Support\MerchantOtpRequestLimiter;
use App\Rules\ValidationWindowDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Open store self-signup (§1 decision 2026-08-15): request-otp → verify-otp
 * → register creates a DRAFT merchant plus its owner account and logs the
 * owner in; the setup wizard then runs behind the merchant guard.
 *
 * Enumeration stance mirrors the customer flow: request-otp answers 200
 * with the same body whether or not the phone is known, verify-otp never
 * says WHY a code failed; only register — reached exclusively with
 * OTP-proven phone possession — may say an email is taken.
 */
class SignupController extends Controller
{
    /**
     * The STORED shape. Callers may send seven local digits instead —
     * withNormalisedPhone() folds those in before this ever runs.
     */
    private const string PHONE_RULE = 'regex:/^\+960[79]\d{6}$/';

    public function __construct(private readonly MerchantOtpService $otp) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $this->withNormalisedPhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', self::PHONE_RULE],
        ]);

        // Dual throttle, keyed separately from the customer flow and SHARED
        // with the mobile signup surface — see MerchantOtpRequestLimiter.
        if ($refusal = MerchantOtpRequestLimiter::hitOrRefuse($validated['phone'], (string) $request->ip())) {
            return $refusal;
        }

        $this->otp->request($validated['phone']);

        // Always 200 with an identical body — known and unknown phones are
        // indistinguishable from outside.
        return response()->json(['message' => 'If the number is valid, a verification code has been sent.']);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $this->withNormalisedPhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', self::PHONE_RULE],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $token = $this->otp->verify($validated['phone'], $validated['code']);
        } catch (TooManyOtpAttemptsException) {
            throw ValidationException::withMessages(['code' => 'otp_attempts_exceeded']);
        } catch (InvalidOtpException) {
            throw ValidationException::withMessages(['code' => 'otp_invalid']);
        }

        return response()->json([
            'data' => [
                'signup_token' => $token,
                'expires_in_minutes' => MerchantOtpService::SIGNUP_TOKEN_TTL_MINUTES,
            ],
        ]);
    }

    /**
     * What the form needs BEFORE it is filled in — today's validation-window
     * range, so the field can show its own limit rather than guessing.
     * Public, like every other signup step, and identical to the mobile
     * door's (Mobile\MerchantSignupController::options).
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => SignupOptions::payload()]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'signup_token' => ['required', 'string'],
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'business_name_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            // How long the new store holds a sale before its cashback
            // validates. The SAME rule object the preferences screen uses,
            // reading the SAME admin-governed ceiling — a store must never
            // be signed up on a window that screen would then refuse.
            // Omitted (or null) means "no opinion": the store is created on
            // the platform default exactly as before this field existed.
            'validation_window_days' => ['sometimes', 'nullable', new ValidationWindowDays],
        ]);

        try {
            $owner = $this->otp->register(
                $validated['signup_token'],
                $validated['business_name'],
                $validated['email'],
                $validated['password'],
                blank($validated['business_name_dv'] ?? null) ? null : trim((string) $validated['business_name_dv']),
                isset($validated['validation_window_days']) ? (int) $validated['validation_window_days'] : null,
            );
        } catch (InvalidSignupTokenException) {
            throw ValidationException::withMessages(['signup_token' => 'signup_token_invalid']);
        } catch (EmailAlreadyRegisteredException) {
            throw ValidationException::withMessages(['email' => 'email_already_registered']);
        }

        // Log in a re-retrieved instance: the guard caches the model, and a
        // wasRecentlyCreated instance would make every later resource
        // response in this lifecycle answer 201.
        Auth::guard('merchant')->login($owner->fresh());
        $request->session()->regenerate();

        return (new MerchantUserResource($owner->loadMissing('merchant')))
            ->response($request)
            ->setStatusCode(201);
    }

    /**
     * Folds whatever the caller sent into the stored E.164 shape BEFORE
     * validation, so every downstream key — the OTP record, the per-phone
     * throttle, the customer row — sees one representation of one number.
     * Normalising later would let "7712345" and "+9607712345" become two
     * accounts for one person, and let someone slip the throttle by
     * alternating between them. An unparseable value is left untouched for
     * the rule below to refuse.
     */
    private function withNormalisedPhone(Request $request): void
    {
        $raw = $request->input('phone');

        if (is_string($raw)) {
            $request->merge(['phone' => Msisdn::normalise($raw) ?? $raw]);
        }
    }
}
