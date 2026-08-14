<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Cashback\CustomerRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * GET /v1/customers/lookup?ref= — cashier confirmation at the till (§9.2,
 * §11): resolves a customer ref — the 6-digit code, or a Maldivian mobile
 * normalised to +960 E.164 (CustomerRef) — to a MASKED name so the right
 * person is credited. Only the masked name ever crosses this API — no
 * balance, phone number, or any other customer data.
 *
 * Enumeration control: the code space is only 10^6 and the local mobile
 * space barely bigger, so a credential walking either could confirm which
 * refs exist and harvest name fragments. A till's legitimate misses are
 * typos by a customer standing at the counter, so failed lookups — code and
 * phone alike, one shared counter — are (a) written to the log as an audit
 * trail and (b) counted per credential — past MISS_LIMIT misses in a day,
 * every lookup on that credential answers 429 until the window rolls, and
 * the trip itself is logged loudly for operations. Successful lookups are
 * never throttled here (the shared vendor-api limit still applies).
 */
class CustomerLookupController extends V1Controller
{
    /** Failed lookups tolerated per credential per rolling day. */
    public const int MISS_LIMIT = 60;

    private const int MISS_DECAY_SECONDS = 86_400;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $this->validateEnvelope($request, [
            'ref' => ['required', 'string', 'regex:'.CustomerRef::PATTERN],
        ]);

        $missKey = $this->missKey($request);

        if (RateLimiter::tooManyAttempts($missKey, self::MISS_LIMIT)) {
            return new JsonResponse(
                ['message' => 'Too Many Attempts.'],
                429,
                ['Retry-After' => (string) RateLimiter::availableIn($missKey)],
            );
        }

        $ref = CustomerRef::parse($data['ref']);

        if ($ref === null) {
            // Unreachable — the regex rule IS the parse pattern — but a
            // guard beats a null deref if the two ever drift.
            return $this->error(422, 'validation_failed', 'The given data was invalid.', errors: [
                'ref' => ['The ref format is invalid.'],
            ]);
        }

        $customer = $ref->resolve();

        if ($customer === null) {
            RateLimiter::hit($missKey, self::MISS_DECAY_SECONDS);

            Log::info('v1 customer lookup miss', [
                'merchant_id' => $request->user()?->getKey(),
                'token_id' => $request->user()?->currentAccessToken()?->getKey(),
                'ref' => $data['ref'],
            ]);

            if (RateLimiter::attempts($missKey) >= self::MISS_LIMIT) {
                Log::warning('v1 customer lookup miss limit tripped — possible customer-code enumeration', [
                    'merchant_id' => $request->user()?->getKey(),
                    'token_id' => $request->user()?->currentAccessToken()?->getKey(),
                    'misses' => RateLimiter::attempts($missKey),
                ]);
            }

            return $this->error(404, 'customer_not_found', sprintf('No customer matches ref "%s".', $data['ref']));
        }

        return new JsonResponse([
            'ref' => $data['ref'],
            // A known ref that cannot currently earn is 200 valid:false —
            // the cashier needs "this code exists but is blocked", not 404.
            'valid' => $customer->status === 'active',
            'masked_name' => $this->maskName((string) $customer->name),
        ]);
    }

    /**
     * Same idiom as the panel's phone masking: keep a short leading
     * fragment, star the rest — per name part, e.g. "Aisha Mohamed" →
     * "Ais*** Moh***". Enough to confirm with the person present.
     */
    private function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return implode(' ', array_map(
            fn (string $part): string => mb_substr($part, 0, 3).'***',
            $parts,
        ));
    }

    /**
     * Misses are counted per CREDENTIAL (EnsureVendorCredential guarantees a
     * real personal access token on every /v1 request), mirroring the
     * vendor-api throttle's keying.
     */
    private function missKey(Request $request): string
    {
        return 'v1-lookup-miss:'.$request->user()->currentAccessToken()->getKey();
    }
}
