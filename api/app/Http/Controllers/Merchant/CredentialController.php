<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Credentials\CredentialAlreadyRevokedException;
use App\Domain\Credentials\CredentialCapReachedException;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\VendorAbility;
use App\Http\Controllers\Controller;
use App\Http\Resources\CredentialResource;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * The merchant's own vendor credentials (§9.1, §13b task #21) — OWNER-only
 * throughout, because a token issued here can write cashback against the
 * store's own money.
 *
 * Self-serve issuance replaces the admin-only path for merchants who bring
 * their own POS: the owner names the integration partner, picks abilities
 * from the closed VendorAbility set, and receives the plaintext token
 * EXACTLY once — the 201 body is the only place it ever exists. Everything
 * afterwards (listings included) carries the metadata and nothing
 * recoverable.
 *
 * Two bounds sit on issuance, both per MERCHANT rather than per user so
 * extra owner accounts cannot multiply the budget:
 *
 *  - ISSUANCE_PER_HOUR (5) — a rate limit, 429 with Retry-After. Minting is
 *    cheap for an attacker with a stolen session and expensive for us: each
 *    token is a live write credential.
 *  - CredentialService::MAX_ACTIVE_PER_MERCHANT (10) — a standing cap on
 *    LIVE credentials, 422 `credential_cap_reached`. Revoking makes room;
 *    waiting does not, so the two refusals are deliberately different codes.
 */
class CredentialController extends Controller
{
    /** Self-serve issuances allowed per merchant per hour. */
    private const int ISSUANCE_PER_HOUR = 5;

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $rows = ApiCredential::query()
            ->where('merchant_id', $user->merchant_id)
            ->with(['posVendor', 'issuedByMerchantUser'])
            ->orderByDesc('id')
            ->get();

        return CredentialResource::collection($rows);
    }

    public function store(Request $request, CredentialService $credentials): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');
        $merchant = $user->merchant;

        // EnsureMerchantApproved has already turned away draft /
        // pending_review / rejected stores (409 store_not_approved). A
        // SUSPENDED (or closed) store clears that gate and is refused here:
        // it is mid-default on the §7 clock and creates no cashback, so a
        // fresh write credential could only mint ineligible traffic and
        // would hand a defaulting store a new integration to point at us.
        // Same 409 shape and the same `store_not_trading` code the panel
        // already knows from the rate and promotion gates.
        if (! $merchant instanceof Merchant || $merchant->status !== 'active') {
            return response()->json([
                'message' => sprintf(
                    'Your store is %s, so new API credentials cannot be created — revoking existing ones still works.',
                    Merchant::statusLabel($merchant?->status),
                ),
                'code' => 'store_not_trading',
            ], 409);
        }

        $validated = $request->validate([
            // The integration partner as the owner names it — free text,
            // because the pos_vendors registry is admin-curated and a
            // merchant must not be able to write into it.
            'label' => ['required', 'string', 'min:2', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in(VendorAbility::values())],
        ]);

        $limiterKey = 'merchant-credential-issue:'.$merchant->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, self::ISSUANCE_PER_HOUR)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'message' => sprintf(
                    'Too many credentials created in the last hour (limit %d). Try again in %d minutes.',
                    self::ISSUANCE_PER_HOUR,
                    max(1, (int) ceil($retryAfter / 60)),
                ),
                'code' => 'issuance_rate_limited',
                'retry_after_seconds' => $retryAfter,
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        try {
            $issued = $credentials->issueForMerchantUser(
                $merchant,
                $validated['label'],
                $validated['abilities'],
                $user,
            );
        } catch (CredentialCapReachedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'credential_cap_reached',
            ], 422);
        }

        // Only a credential that actually exists spends budget — a refused
        // cap or a validation error must not lock the owner out of the fix.
        RateLimiter::hit($limiterKey, 3600);

        return response()->json([
            // Shown once. We store only the SHA-256 digest — losing this
            // string means issuing a new credential, never recovering it.
            'plaintext_token' => $issued->plainTextToken,
            'credential' => new CredentialResource(
                $issued->credential->load(['posVendor', 'issuedByMerchantUser']),
            ),
        ], 201);
    }

    public function destroy(Request $request, int $id, CredentialService $credentials): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        // Scoped by merchant, so another store's credential id is a 404 —
        // indistinguishable from a nonexistent one, and never an oracle for
        // how many credentials anyone else holds.
        $credential = ApiCredential::query()
            ->where('merchant_id', $user->merchant_id)
            ->findOrFail($id);

        try {
            $credential = $credentials->revoke($credential, $user);
        } catch (CredentialAlreadyRevokedException $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json([
            'data' => new CredentialResource(
                $credential->load(['posVendor', 'issuedByMerchantUser']),
            ),
        ]);
    }
}
