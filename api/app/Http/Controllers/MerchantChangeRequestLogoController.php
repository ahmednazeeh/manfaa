<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Onboarding\MerchantLogo;
use App\Models\AdminUser;
use App\Models\MerchantChangeRequest;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two sides of a queued LOGO change (MR9): `?side=proposed` streams the
 * staged file the merchant uploaded, `?side=current` the one it would
 * replace as recorded in the snapshot.
 *
 * Why a route of its own, rather than MerchantLogoController: that one
 * serves whatever `merchants.logo_path` points at, which is by definition
 * NOT the pending file — a reviewer deciding on a new logo needs to see the
 * new logo, and the merchant's panel needs to show what it has waiting.
 *
 * NEVER public, whatever the store's status: a staged logo is a proposal,
 * and the storefront must not serve it until an admin says so. The audience
 * is any authenticated admin (the review sheet) or the store's own merchant
 * users (their pending-review state) — everyone else gets the 404 an unknown
 * id gets, exactly as an unapproved store's logo is treated.
 *
 * Unauthenticated by route, authorised in the handler — the pattern
 * routes/api/logos.php explains: Sanctum's stateful middleware has already
 * hydrated the session guards, so an <img> from either panel is recognised
 * without forcing the route behind one guard's wall.
 */
class MerchantChangeRequestLogoController extends Controller
{
    public function __invoke(Request $request, int $id): StreamedResponse
    {
        $changeRequest = MerchantChangeRequest::query()->find($id);

        if ($changeRequest === null || ! $this->mayView($request, $changeRequest)) {
            abort(404);
        }

        $source = $request->query('side') === 'current'
            ? (array) $changeRequest->snapshot
            : (array) $changeRequest->payload;

        $path = $source['logo_path'] ?? null;

        if (! is_string($path) || $path === '') {
            abort(404);
        }

        $disk = Storage::disk(MerchantLogo::DISK);

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, headers: [
            'Content-Type' => MerchantLogo::mime($path),
            // Belt and braces on a file the upload validator already proved
            // is a raster image: never let a browser sniff it into anything
            // executable served from our own origin.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // The authorisation answer depends on the caller, and a proposal
            // must never sit in a shared cache.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function mayView(Request $request, MerchantChangeRequest $changeRequest): bool
    {
        if ($request->user('admin') instanceof AdminUser) {
            return true;
        }

        $user = $request->user('merchant');

        return $user instanceof MerchantUser && $user->merchant_id === $changeRequest->merchant_id;
    }
}
