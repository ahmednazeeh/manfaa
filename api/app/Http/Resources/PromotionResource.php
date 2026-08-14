<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One promotion for the merchant and admin panels. The fee is never stored
 * on the promotion — it follows the promo rate's §4 tier, resolved here for
 * display exactly as it will be resolved at credit time. Timestamps render
 * in the business timezone like every other panel surface.
 *
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $now = CarbonImmutable::now('UTC');

        // Fee priced under the schedule in force when the promotion runs: at
        // its start for a future window, at now once it has started. Lenient
        // (null fee fields) so a stale DRAFT whose rate that schedule no
        // longer prices — drafts never block an admin schedule change —
        // cannot 500 the whole listing; the merchant must still be able to
        // see and cancel it. Publish refuses such a draft outright.
        $feeInstant = $this->starts_at->utc()->isAfter($now) ? $this->starts_at->utc() : $now;

        return RateResource::tryDescribeBp($this->rate_bp, $feeInstant) + [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'is_live' => $this->status === 'published'
                && $this->starts_at->lessThanOrEqualTo($now)
                && $this->ends_at->isAfter($now),
            'starts_at' => $this->starts_at->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->setTimezone($timezone)->toIso8601String(),
            'min_purchase_laari' => $this->min_purchase_laari,
            'max_cashback_per_customer_laari' => $this->max_cashback_per_customer_laari,
            'published_at' => $this->published_at?->setTimezone($timezone)->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->setTimezone($timezone)->toIso8601String(),
        ];
    }
}
