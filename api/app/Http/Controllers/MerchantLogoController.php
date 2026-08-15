<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Onboarding\MerchantLogo;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONE way a store logo is ever read (PLAN §1: "the store is invisible
 * publicly until approved").
 *
 * Logos live on the private `logos` disk — no disk URL, `serve` off, root
 * outside storage/app/public — so no path reaches a file directly. This
 * route decides, per request, from the merchant's CURRENT status:
 *
 *  - `active` — anyone. The store is listed on /discover and has a public
 *    store page; its logo is part of that public payload, and `logo_url`
 *    in every discovery response points here.
 *  - anything else (draft, pending_review, rejected, suspended, closed) —
 *    the store's own merchant users, or any authenticated admin. Everyone
 *    else gets the same 404 an unknown slug gets: the response must never
 *    reveal that a store exists but is not approved, exactly as
 *    DiscoveryService::store() refuses to distinguish those cases.
 *
 * Before this route, logos sat on the public disk at the guessable path
 * /storage/merchants/{id}/logo.png — walking the integer id enumerated the
 * branding of every store that had ever started the wizard, approved or not.
 *
 * Caching: the URL carries a content-derived `v` (MerchantLogo::url), so a
 * replaced logo is a different URL and a public response can be cached
 * immutably for a year. Non-public responses are `private, no-store` — a
 * shared cache must never hold an unapproved store's logo, and the
 * authorisation answer depends on the caller.
 */
class MerchantLogoController extends Controller
{
    public function __invoke(Request $request, string $slug): StreamedResponse
    {
        $merchant = Merchant::query()
            ->where('slug', $slug)
            ->first(['id', 'slug', 'status', 'logo_path']);

        if ($merchant === null || $merchant->logo_path === null || $merchant->logo_path === '') {
            abort(404);
        }

        $public = $merchant->status === 'active';

        if (! $public && ! $this->mayViewPrivately($request, $merchant)) {
            abort(404);
        }

        $disk = Storage::disk(MerchantLogo::DISK);

        if (! $disk->exists($merchant->logo_path)) {
            abort(404);
        }

        return $disk->response($merchant->logo_path, headers: [
            'Content-Type' => MerchantLogo::mime($merchant->logo_path),
            // Belt and braces on a file the upload validator already proved
            // is a raster image: never let a browser sniff it into anything
            // executable served from our own origin.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => $public
                ? 'public, max-age=31536000, immutable'
                : 'private, no-store',
        ]);
    }

    /**
     * The pre-public audience: the store's own merchant users (the wizard
     * and the settings screen render their own logo before approval) and
     * any authenticated admin (the review queue shows it in the approval
     * sheet). Role is deliberately NOT checked — a store's own staff seeing
     * their own shop's logo is not a privilege boundary; the owner-only
     * gates live on the endpoints that CHANGE it.
     */
    private function mayViewPrivately(Request $request, Merchant $merchant): bool
    {
        if ($request->user('admin') instanceof AdminUser) {
            return true;
        }

        $user = $request->user('merchant');

        return $user instanceof MerchantUser && $user->merchant_id === $merchant->id;
    }
}
