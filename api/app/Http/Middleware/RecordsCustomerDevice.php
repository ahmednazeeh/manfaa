<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Referrals\DeviceIdentity;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feeds the self-referral defence from the customer app (owner, 2026-08-24).
 *
 * The app sends its sanctioned per-OS identifier — Android SSAID, iOS
 * identifierForVendor — as `X-Device-Id` (with an optional
 * `X-Device-Platform`), and iOS ALSO sends its Keychain-persisted UUID as
 * `X-Device-Ref` — the one identifier that survives a delete-and-reinstall,
 * which rotates the IFV. Both are recorded, each as its own sighting, so a
 * reinstalled fraudster still collides on the kc: hash. This middleware,
 * mounted on the authed mobile CUSTOMER tree, records the keyed hashes
 * against whoever the bearer token proved. The very first authed request
 * after signup covers signup itself, so no signup endpoint needs its own
 * hook.
 *
 * DeviceIdentity::record() carries the hourly cache guard, so a warm repeat
 * request costs one cache hit and no DB write. Absent or malformed headers
 * record nothing and never fail the request.
 */
final class RecordsCustomerDevice
{
    public function __construct(private readonly DeviceIdentity $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Customer) {
            $platform = strtolower((string) $request->header(DeviceIdentity::HEADER_PLATFORM, 'android'));
            $platform = in_array($platform, ['android', 'ios'], true) ? $platform : 'android';

            foreach ([DeviceIdentity::HEADER_ID, DeviceIdentity::HEADER_REF] as $header) {
                $raw = $request->header($header);

                if (is_string($raw) && $raw !== '') {
                    $this->devices->record($user, $raw, $platform);
                }
            }
        }

        return $next($request);
    }
}
