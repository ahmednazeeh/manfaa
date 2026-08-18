<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Publication\StorePublicationService;
use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The store's own on/off switch (owner decision 2026-08-18).
 *
 * No approval queue, either direction — see StorePublicationService for why.
 * The response says whether customers were told, because the merchant is
 * entitled to know that the second toggle of the day reached nobody rather
 * than assume it did.
 */
final class PublicationController extends Controller
{
    public function __invoke(Request $request, StorePublicationService $publication): JsonResponse
    {
        $data = $request->validate([
            'published' => ['required', 'boolean'],
        ]);

        $user = $request->user('merchant');
        abort_unless($user instanceof MerchantUser, 403);

        $merchant = $user->merchant;
        abort_if($merchant === null, 403);

        $notified = $data['published']
            ? $publication->republish($merchant)
            : $publication->unpublish($merchant);

        return new JsonResponse([
            'data' => [
                'published' => $merchant->unpublished_at === null,
                'unpublished_at' => $merchant->unpublished_at?->toIso8601String(),
                // Whether THIS call sent anything. False means either nothing
                // changed, or the day's message has already gone out.
                'customers_notified' => $notified,
            ],
        ]);
    }
}
