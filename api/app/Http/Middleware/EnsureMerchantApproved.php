<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the post-approval merchant write surface (settings profile, §7 rate
 * change, promotion create/publish) to stores that have passed review.
 *
 * Before approval the setup wizard is the ONLY write path: draft and
 * rejected stores edit through /merchant/setup/* — whose domain layer knows
 * the wizard semantics, e.g. replacing the untraded initial rate row
 * instead of appending — and a pending_review store is frozen entirely,
 * otherwise the owner could rewrite the very fields the superadmin queue is
 * reviewing (category, terms, channel, rate) between submit and approval.
 *
 * Runs behind auth:merchant. 409 with machine-readable
 * `code: store_not_approved` (the state-conflict shape the wizard's own
 * setup_not_editable uses) so the panel can route the owner back to the
 * wizard instead of parsing prose.
 */
class EnsureMerchantApproved
{
    private const array PRE_APPROVAL_STATUSES = ['draft', 'pending_review', 'rejected'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('merchant');
        $status = $user instanceof MerchantUser ? $user->merchant?->status : null;

        if ($status === null || in_array($status, self::PRE_APPROVAL_STATUSES, true)) {
            return new JsonResponse([
                'message' => sprintf(
                    'This action is unavailable while the store is %s — complete the setup wizard and wait for approval.',
                    $status ?? 'unknown',
                ),
                'code' => 'store_not_approved',
            ], 409);
        }

        return $next($request);
    }
}
