<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * GET /merchant/customers/lookup?code=NNNNNN — the credit screen's cashier
 * confirmation (§11 phone-recycling control): resolves a 6-digit customer
 * code to a MASKED name so the right person is credited before a manual
 * credit is posted. Staff-accessible — posting credits is staff work.
 *
 * Enumeration posture, two layers (mirroring the V1 vendor lookup):
 *
 * - An unknown (or non-creditable) code is a plain 200 `{valid: false}` —
 *   no error shape, no existence oracle beyond the boolean the cashier
 *   needs — and the route is throttled 30/min per user, matching the
 *   manual-credit throttle.
 * - Misses are additionally counted per MERCHANT per rolling day. The
 *   per-user throttle alone multiplies with every staff account, so the
 *   miss budget is keyed on the merchant: extra accounts share one
 *   allowance and buy an attacker nothing. A till's legitimate misses are
 *   typos by a customer standing at the counter; past MISS_LIMIT in a day
 *   every lookup for that merchant answers 429 until the window rolls.
 *   Each miss is logged as an audit trail and the trip itself is logged
 *   loudly for operations — enumeration must never be silent.
 */
class CustomerLookupController extends Controller
{
    /** Non-creditable lookups tolerated per merchant per rolling day. */
    public const int MISS_LIMIT = 60;

    private const int MISS_DECAY_SECONDS = 86_400;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $missKey = 'merchant-lookup-miss:'.$user->merchant_id;

        if (RateLimiter::tooManyAttempts($missKey, self::MISS_LIMIT)) {
            return new JsonResponse(
                ['message' => 'Too Many Attempts.'],
                429,
                ['Retry-After' => (string) RateLimiter::availableIn($missKey)],
            );
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $customer = Customer::query()->where('customer_code', $data['code'])->first();

        // Unknown code and known-but-blocked customer answer identically:
        // the credit screen only needs "can I credit this code". Both count
        // against the merchant's miss budget for the same reason.
        if ($customer === null || $customer->status !== 'active') {
            RateLimiter::hit($missKey, self::MISS_DECAY_SECONDS);

            Log::info('merchant panel customer lookup miss', [
                'merchant_id' => $user->merchant_id,
                'merchant_user_id' => $user->getKey(),
                'code' => $data['code'],
            ]);

            if (RateLimiter::attempts($missKey) >= self::MISS_LIMIT) {
                Log::warning('merchant panel lookup miss limit tripped — possible customer-code enumeration', [
                    'merchant_id' => $user->merchant_id,
                    'merchant_user_id' => $user->getKey(),
                    'misses' => RateLimiter::attempts($missKey),
                ]);
            }

            return new JsonResponse(['valid' => false]);
        }

        return new JsonResponse([
            'valid' => true,
            'masked_name' => $this->maskName((string) $customer->name),
        ]);
    }

    /**
     * The V1 lookup's masking idiom (V1\CustomerLookupController): keep a
     * short leading fragment, star the rest — per name part, e.g.
     * "Aisha Mohamed" → "Ais*** Moh***". Enough to confirm with the person
     * present; nothing else about the customer crosses this endpoint.
     */
    private function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return implode(' ', array_map(
            fn (string $part): string => mb_substr($part, 0, 3).'***',
            $parts,
        ));
    }
}
