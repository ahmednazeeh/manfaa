<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Customers\Msisdn;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Device sign-in for the customer app.
 *
 * Deliberately NOT Auth::guard('customer')->attempt(): that establishes a
 * session, and this endpoint must not — a bearer client has no session, and
 * creating one here would set a cookie no app will ever send back while
 * quietly widening what a stolen response is worth. The credential check is
 * done directly and the token is minted from it.
 *
 * The password path mirrors the web login exactly, so no new authentication
 * semantics enter the system with this round. Passwordless OTP sign-in for a
 * NEW DEVICE is the better mobile experience and is flagged in
 * PLAN-mobile-api.md as an owner decision — it is a real product change
 * (SIM-swap becomes account takeover), not a refactor, and does not belong
 * in a plumbing round.
 */
final class CustomerTokenController extends Controller
{
    use ThrottlesSignIn;

    public function __construct(private readonly MobileTokenService $tokens) {}

    public function store(Request $request): JsonResponse
    {
        // Fold seven local digits into the stored E.164 shape before the
        // lookup, exactly as OtpAuthController does, so "7712345" and
        // "+9607712345" cannot become two different accounts' logins.
        $raw = $request->input('phone');

        if (is_string($raw)) {
            // An unparseable value is left as typed for validation to refuse,
            // never blanked — same as OtpAuthController::withNormalisedPhone.
            $request->merge(['phone' => Msisdn::normalise($raw) ?? $raw]);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        // Per-ACCOUNT, not per-IP. The route throttle keys on $request->ip(),
        // which under trustProxies resolves from a header the client sends —
        // so rotating one header buys a fresh bucket on every request. A
        // counter on the submitted identity is the only limit here an
        // attacker cannot spin around. Same dual shape OtpAuthController
        // already uses, which calls its route throttle "a coarse backstop".
        $throttleKey = 'mobile-signin:phone:'.$validated['phone'];

        $this->assertNotThrottled($throttleKey, 'phone');

        $customer = Customer::query()->where('phone', $validated['phone'])->first();

        // One refusal for every cause — wrong number, wrong password,
        // suspended, closed. Hash::check runs against a dummy when there is
        // no customer so an unknown phone and a wrong password cost the same
        // wall-clock time and cannot be told apart by timing.
        $passwordMatches = $customer !== null
            ? Hash::check($validated['password'], (string) $customer->password)
            : Hash::check($validated['password'], self::dummyHash());

        if ($customer === null || ! $passwordMatches || ! $customer->mayUseMobileApp()) {
            $this->recordFailedSignIn($throttleKey);

            throw ValidationException::withMessages(['phone' => __('auth.failed')]);
        }

        $this->clearSignInAttempts($throttleKey);

        $issued = $this->tokens->issue(
            $customer,
            MobileAudience::Customer,
            $validated['device_name'],
        );

        return response()->json([
            'data' => [
                'token' => $issued->plainTextToken,
                'expires_at' => $issued->expiresAt->toIso8601ZuluString(),
                'device_name' => $issued->deviceName,
                'customer' => [
                    'id' => $customer->getKey(),
                    'name' => $customer->name,
                    'customer_code' => $customer->customer_code,
                ],
            ],
        ], 201);
    }

    /**
     * Revoke the token this request authenticated with — sign out THIS
     * device only. A lost phone is `destroyAll`, not this.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $this->tokens->revokeCurrent($customer);

        return response()->json(null, 204);
    }

    /** Sign out everywhere — the remedy for a lost or stolen phone. */
    public function destroyAll(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $revoked = $this->tokens->revokeAll($customer, MobileAudience::Customer);

        return response()->json(['data' => ['revoked' => $revoked]]);
    }

    /**
     * A valid bcrypt digest of a value nothing will ever submit, used only to
     * spend the same time on an unknown phone as on a known one.
     */
    private static function dummyHash(): string
    {
        return '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    }
}
