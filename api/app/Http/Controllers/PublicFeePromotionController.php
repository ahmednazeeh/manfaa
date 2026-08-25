<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Platform\FeePromotionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * THE OFFER ON THE FRONT DOOR (owner, 2026-08-25): the merchant landing page
 * is public and unauthenticated, and the promotions have to be visible on it
 * — that is the acquisition channel the whole feature exists for.
 *
 * A SEPARATE ROUTE, not a flag on the merchant one, because the two answer
 * genuinely different questions and only one of them is safe to say to a
 * stranger:
 *
 *   /api/merchant/fee-promotion   what THIS store is being charged, and when
 *                                 THEIR window closes.
 *   /api/public/fee-promotion     what is on offer to whoever signs up next.
 *
 * WHAT IS DELIBERATELY NOT HERE. No merchant, no merchant's dates, no count
 * of who is enrolled, no approval stamps — nothing a visitor could use to
 * learn anything about a particular store. The introductory offer is
 * published as "X days at Y%", never as a date, because a visitor has no
 * approval stamp and any date we printed would be a promise about a merchant
 * they are not yet. The platform-wide window's END is published, because it
 * is the platform's own campaign deadline and is meant to be on the poster.
 *
 * THROTTLED at 120 requests a minute per IP (routes/api/fee-promotions.php),
 * matching the other unauthenticated read endpoints: a landing page hits this
 * once per visit, and an unauthenticated route that touches the database
 * needs a ceiling whether or not anyone is abusing it. The reply is served
 * from FeePromotionPolicy's 60-second cache, so the load a burst can actually
 * place on Postgres is one query a minute.
 */
final class PublicFeePromotionController extends Controller
{
    public function __construct(private readonly FeePromotionPolicy $promotions) {}

    public function __invoke(): JsonResponse
    {
        $offers = $this->promotions->publicOffers(CarbonImmutable::now('UTC'));

        return new JsonResponse([
            'data' => [
                'active' => $offers !== [],
                'offers' => array_map(fn ($offer): array => $offer->toPublicArray(), $offers),
            ],
        ]);
    }
}
