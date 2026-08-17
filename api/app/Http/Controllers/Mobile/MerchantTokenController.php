<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Mobile\IssuedMobileToken;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Device sign-in for the merchant app.
 *
 * Same stance as the customer controller: no session is established, the
 * credential is checked directly, and a deactivated staff member fails
 * exactly like a wrong password — no account-state oracle, matching
 * MerchantAuthController::login.
 */
final class MerchantTokenController extends Controller
{
    use ThrottlesSignIn;

    public function __construct(private readonly MobileTokenService $tokens) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        // Per-ACCOUNT, not per-IP — see the ThrottlesSignIn docblock: the
        // route throttle keys on a client-supplied header and buys nothing.
        $throttleKey = 'mobile-signin:email:'.mb_strtolower($validated['email']);

        $this->assertNotThrottled($throttleKey, 'email');

        $user = MerchantUser::query()->where('email', $validated['email'])->first();

        $passwordMatches = $user !== null
            ? Hash::check($validated['password'], (string) $user->password)
            : Hash::check($validated['password'], self::dummyHash());

        if ($user === null || ! $passwordMatches || ! $user->mayUseMobileApp()) {
            $this->recordFailedSignIn($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $this->clearSignInAttempts($throttleKey);

        $issued = $this->tokens->issue(
            $user,
            MobileAudience::Merchant,
            $validated['device_name'],
        );

        return response()->json(['data' => self::signedInPayload($user, $issued)], 201);
    }

    /**
     * The merchant signed-in shape — ONE definition, answered by both doors
     * that mint a merchant token: this password sign-in and the mobile
     * signup's register step (MerchantSignupController). The app parses one
     * payload however the token was earned.
     *
     * @return array<string, mixed>
     */
    public static function signedInPayload(MerchantUser $user, IssuedMobileToken $issued): array
    {
        $user->loadMissing('merchant');

        return [
            'token' => $issued->plainTextToken,
            'expires_at' => $issued->expiresAt->toIso8601ZuluString(),
            'device_name' => $issued->deviceName,
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'merchant' => [
                'id' => $user->merchant?->getKey(),
                'name' => $user->merchant?->name,
                'slug' => $user->merchant?->slug,
            ],
            // The till builds its navigation from this. A fixed tab bar
            // would show a cashier the settlement builder and refuse it
            // on tap; the app should not draw what the account may not
            // reach (PLAN-mobile-api.md §1).
            'permissions' => $user->resolvedPermissions(),
        ];
    }

    /** Sign out THIS till device. */
    public function destroy(Request $request): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $this->tokens->revokeCurrent($user);

        return response()->json(null, 204);
    }

    /** Sign out every device this staff account holds. */
    public function destroyAll(Request $request): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $revoked = $this->tokens->revokeAll($user, MobileAudience::Merchant);

        return response()->json(['data' => ['revoked' => $revoked]]);
    }

    private static function dummyHash(): string
    {
        return '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    }
}
