<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the post-approval merchant write surface, in two strengths.
 *
 * **`approved` (default)** — refuses the three PRE-approval statuses.
 * Before approval the setup wizard is the ONLY write path: draft and
 * rejected stores edit through /merchant/setup/* — whose domain layer knows
 * the wizard semantics, e.g. replacing the untraded initial rate row
 * instead of appending — and a pending_review store is frozen entirely,
 * otherwise the owner could rewrite the very fields the superadmin queue is
 * reviewing (category, terms, channel, rate) between submit and approval.
 * This is the gate for writes that must not disturb the review: the profile
 * PATCH, branch writes, staff invites.
 *
 * **`trading`** — refuses everything that is not `active`, i.e. the three
 * pre-approval statuses PLUS suspended and closed. This is the gate for the
 * COMMERCIAL OFFER: the standing rate, promotions, and the product-category
 * rate card.
 *
 * Why suspension belongs in the stricter tier (decision 2026-08-15, PLAN
 * §7). Suspension is the platform's only credit control, applied
 * automatically on day 16 of non-payment, and it stops cashback CREATION.
 * A suspended store therefore cannot honour any offer it sets — so:
 *
 *  - a rate change would fire the §9.3 `merchant.rate_changed` webhook and
 *    move `GET /v1/merchants/me/rate`, telling every integrated till to
 *    advertise a percentage that will accrue exactly nothing;
 *  - a promotion is IMMUTABLE once published and cannot be ended early
 *    (§7), so a suspended store could publish a 20% campaign that springs
 *    live the instant an admin reinstates it — after, and because of, the
 *    settlement conversation that reinstatement turns on. The pre-approval
 *    freeze exists for precisely that reason; a store suspended for
 *    non-payment is not a weaker case than one that has not been reviewed.
 *
 * Everything else a suspended store may still do — settle, upload receipts,
 * read every screen, fix its profile, run its branches and staff — is
 * untouched: suspension must not stop a merchant from doing the one thing
 * that ends it.
 *
 * Runs behind auth:merchant. 409 with a machine-readable code (the
 * state-conflict shape the wizard's own setup_not_editable uses):
 * `store_not_approved` when the store has not passed review — the panel
 * routes the owner back to the wizard — and `store_not_trading` when an
 * approved store is suspended or closed, which is a different conversation
 * and must not tell a shopkeeper to "complete the setup wizard".
 */
class EnsureMerchantApproved
{
    private const array PRE_APPROVAL_STATUSES = ['draft', 'pending_review', 'rejected'];

    /** Route parameter: the stricter, active-only gate. */
    public const string TRADING = 'trading';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'approved'): Response
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

        if ($mode === self::TRADING && $status !== 'active') {
            return new JsonResponse([
                'message' => sprintf(
                    'Your store is %s, so it is not creating cashback right now — its rates, promotions and category pricing are frozen until it is reinstated.',
                    Merchant::statusLabel($status),
                ),
                'code' => 'store_not_trading',
            ], 409);
        }

        return $next($request);
    }
}
