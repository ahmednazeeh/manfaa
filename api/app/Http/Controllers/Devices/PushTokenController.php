<?php

declare(strict_types=1);

namespace App\Http\Controllers\Devices;

use App\Domain\Mobile\MobileAudience;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Where an app hands over its push registration.
 *
 * The row is bound to the AUTH TOKEN the request arrived on, and cascades
 * when that token is deleted. So every revocation path already built —
 * signing out, cutting a device off from the website, the token cap evicting
 * the least recently used, a staff member being deactivated — stops the push
 * without anybody having to remember to. That is the property that keeps a
 * sold or stolen phone from going on announcing someone's balance on its
 * lock screen.
 */
abstract class PushTokenController extends Controller
{
    abstract protected function audience(): MobileAudience;

    /**
     * Register or refresh this device's push token.
     *
     * PUT, not POST: an app re-sends its token on every launch (providers
     * rotate them freely) and the operation has to be idempotent. Keyed on
     * the provider token itself, so the same physical device re-registering
     * — after a reinstall, a rotation, or a second person signing in on a
     * shared phone — MOVES the row rather than leaving a twin that would go
     * on delivering to whoever held it last.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'app_build' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'locale' => ['sometimes', 'string', 'in:en,dv'],
        ]);

        $user = $this->user($request);
        $accessToken = $user->currentAccessToken();

        // Unreachable behind EnsureMobileToken, which proves a real personal
        // access token before anything here runs. Asserted anyway because
        // the FK below is not nullable and a silent null would be a 500.
        if (! $accessToken instanceof PersonalAccessToken) {
            return response()->json(['error' => [
                'code' => 'unauthenticated',
                'message' => 'Please sign in again.',
            ]], 401);
        }

        DB::transaction(function () use ($validated, $user, $accessToken): void {
            $existing = DeviceToken::query()->where('token', $validated['token'])->first();

            // A registration held by SOMEONE ELSE is destroyed and recreated,
            // never transferred. The handover itself is legitimate — one
            // handset, a new person signing in — but repointing the row would
            // let a caller holding another account's registration token claim
            // it and then, through the owner-scoped destroy(), silence a
            // phone that is not theirs. Matching on the tokenable instead is
            // NOT the fix: `token` is unique, so a routine launch would 500.
            if ($existing !== null && (
                $existing->tokenable_type !== $user->getMorphClass()
                || $existing->tokenable_id !== $user->getKey()
            )) {
                $existing->delete();
                $existing = null;
            }

            $attributes = [
                'token' => $validated['token'],
                'tokenable_type' => $user->getMorphClass(),
                'tokenable_id' => $user->getKey(),
                'personal_access_token_id' => $accessToken->getKey(),
                'platform' => $validated['platform'],
                'last_seen_at' => now(),
            ];

            // Only what the caller ACTUALLY sent. Both fields are optional,
            // and a launch-time refresh sending {token, platform} would
            // otherwise reset a device's stored language to English.
            foreach (['app_build', 'locale'] as $optional) {
                if (array_key_exists($optional, $validated)) {
                    $attributes[$optional] = $validated[$optional];
                }
            }

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                return;
            }

            DeviceToken::query()->create($attributes + ['locale' => $validated['locale'] ?? 'en']);
        });

        return response()->json(null, 204);
    }

    /**
     * Stop pushing to this device — the in-app "turn notifications off",
     * distinct from signing out.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $user = $this->user($request);

        // Scoped through the holder's own rows: someone else's registration
        // token matches nothing, so one account cannot silence another's
        // phone by guessing.
        DeviceToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(null, 204);
    }

    protected function user(Request $request): Customer|MerchantUser
    {
        /** @var Customer|MerchantUser $user */
        $user = $request->user($this->audience()->guard());

        return $user;
    }
}
