<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\InvalidSignupTokenException;
use App\Domain\Customers\Msisdn;
use App\Domain\Customers\OtpService;
use App\Domain\Customers\PhoneAlreadyRegisteredException;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Customer signup with phone OTP (§10 apps/web, §12 Phase 3):
 * request-otp → verify-otp → register. The existing password login is a
 * separate, untouched route.
 *
 * Enumeration stance: request-otp answers 200 with the same body whether or
 * not the phone has an account, and verify-otp never says WHY a code failed.
 * Only register — reached exclusively with OTP-proven phone possession —
 * may say "already registered".
 */
class OtpAuthController extends Controller
{
    /**
     * The STORED shape. Callers may send seven local digits instead —
     * withNormalisedPhone() folds those in before this ever runs.
     */
    private const string PHONE_RULE = 'regex:/^\+960[79]\d{6}$/';

    private const int PHONE_LIMIT_PER_HOUR = 3;

    private const int IP_LIMIT_PER_HOUR = 10;

    public function __construct(private readonly OtpService $otp) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $this->withNormalisedPhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', self::PHONE_RULE],
        ]);

        // Dual throttle (§11-adjacent abuse control): 3/hour per phone so a
        // number cannot be SMS-bombed, 10/hour per IP so one caller cannot
        // spray codes across many numbers.
        $phoneKey = 'otp-request:phone:'.$validated['phone'];
        $ipKey = 'otp-request:ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($phoneKey, self::PHONE_LIMIT_PER_HOUR)
            || RateLimiter::tooManyAttempts($ipKey, self::IP_LIMIT_PER_HOUR)) {
            $retryAfter = max(RateLimiter::availableIn($phoneKey), RateLimiter::availableIn($ipKey));

            return response()->json(
                ['message' => 'Too many verification requests. Try again later.'],
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        RateLimiter::hit($phoneKey, 3600);
        RateLimiter::hit($ipKey, 3600);

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
                'expires_in_minutes' => OtpService::SIGNUP_TOKEN_TTL_MINUTES,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $this->withNormalisedPhone($request);

        $validated = $request->validate([
            'signup_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        try {
            $customer = $this->otp->register(
                $validated['signup_token'],
                $validated['name'],
                $validated['password'],
            );
        } catch (InvalidSignupTokenException) {
            throw ValidationException::withMessages(['signup_token' => 'signup_token_invalid']);
        } catch (PhoneAlreadyRegisteredException) {
            throw ValidationException::withMessages(['phone' => 'phone_already_registered']);
        }

        // Log in a re-retrieved instance: the guard caches the model, and a
        // wasRecentlyCreated instance would make every later resource
        // response in this lifecycle answer 201.
        Auth::guard('customer')->login($customer->fresh());
        $request->session()->regenerate();

        return (new CustomerResource($customer))
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
