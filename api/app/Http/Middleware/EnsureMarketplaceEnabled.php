<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\PlatformConfig;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The marketplace kill switch, enforced at the door
 * (PLAN-marketplace.md §10).
 *
 * The owner's requirement is that a superadmin can hide the marketplace
 * everywhere: the Market tab, the customer web storefront, order tracking,
 * every merchant setting and menu, the admin queues. Hiding is a client
 * decision, so the SERVER has to be the one that means it — otherwise the
 * feature is merely invisible, and an old app build or a curled URL walks
 * straight past it.
 *
 * So every marketplace route sits behind this, and while the switch is off
 * they answer 404 rather than 403. A 403 would confirm the route exists and
 * that something is being withheld; a platform that has not launched a
 * product should simply not appear to have one.
 */
class EnsureMarketplaceEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(PlatformConfig::class)->marketplaceEnabled()) {
            return new JsonResponse([
                'message' => 'Not found.',
                'code' => 'marketplace_disabled',
            ], 404);
        }

        return $next($request);
    }
}
